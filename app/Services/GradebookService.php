<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Aggregates the per-student grade snapshot for one course.
 *
 * The gradebook is read-only: the ledger row is written wherever the assessment
 * is graded (quiz attempt or assignment submission) and this service only groups
 * and averages it. A quiz that allows retakes produces one ledger row per graded
 * attempt, so a student's cell is their best attempt, exactly like
 * `Quiz::bestAttemptFor()`. An assignment produces one row per student-submission,
 * so its cell is that row.
 *
 * The course percentage is the simple mean of every graded assessment's
 * percentage. Categories, weights and dropped grades are later slices; until
 * then every assessment counts equally and one that was never graded counts for
 * nothing (shown as a dash rather than a zero, so an untaken assessment does not
 * drag the average down).
 */
class GradebookService
{
    public function courseGradebook(Course $course): array
    {
        $assessments = $this->assessments($course);

        $enrollments = Enrollment::where('course_id', $course->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->with('student:id,name,email')
            // Students as rows, in a stable order. The correlated subquery sorts
            // without joining users into the enrollments query scope.
            ->orderBy(User::select('name')->whereColumn('users.id', 'enrollments.student_id'))
            ->get();

        $grades = Grade::where('course_id', $course->id)
            ->whereIn('source_type', [QuizAttempt::class, AssignmentSubmission::class])
            ->with('source')
            ->get();

        [$bestQuizByStudent, $assignmentByStudent] = $this->indexedByAssessment($grades);

        $students = $enrollments->map(fn (Enrollment $enrollment): array => $this->studentRow(
            $enrollment,
            $assessments,
            $bestQuizByStudent,
            $assignmentByStudent,
        ));

        $averages = $this->assessmentAverages($assessments->pluck('key')->all(), $students);

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'assessments' => $assessments->map(fn (array $assessment): array => [
                'key' => $assessment['key'],
                'type' => $assessment['type'],
                'id' => $assessment['id'],
                'title' => $assessment['title'],
                'average' => $averages[$assessment['key']] ?? null,
            ])->values()->all(),
            'students' => $students->values()->all(),
        ];
    }

    /**
     * Every assessment columns list, quizzes and assignments together in title
     * order. Each carries the id again so a column maps back to its quiz or
     * assignment without parsing the key.
     *
     * @return array<int, array{key: string, type: string, id: int, title: string}>
     */
    private function assessments(Course $course): \Illuminate\Support\Collection
    {
        return collect()
            ->concat($course->quizzes()->get(['id', 'title'])->map(fn ($quiz): array => [
                'key' => "quiz-{$quiz->id}",
                'type' => 'quiz',
                'id' => (int) $quiz->id,
                'title' => $quiz->title,
            ]))
            ->concat($course->assignments()->get(['id', 'title'])->map(fn ($assignment): array => [
                'key' => "assignment-{$assignment->id}",
                'type' => 'assignment',
                'id' => (int) $assignment->id,
                'title' => $assignment->title,
            ]))
            ->sortBy(fn (array $assessment): string => strtolower($assessment['title']))
            ->values();
    }

    /**
     * Group the course's graded ledger rows by the assessment they belong to.
     *
     * The underlying quiz or submission is reached through the polymorphic
     * `source`, because a grade's `source_id` is an attempt or a submission id,
     * not the quiz or assignment that names the column.
     *
     * @param  Collection<int, Grade>  $grades
     * @return array{
     *     0: array<int, array<int, Grade>>,
     *     1: array<int, array<int, Grade>>,
     * }
     */
    private function indexedByAssessment(Collection $grades): array
    {
        $bestQuizByStudent = [];
        $assignmentByStudent = [];

        foreach ($grades as $grade) {
            if ($grade->source_type === QuizAttempt::class) {
                $attempt = $grade->source;

                // A ledger row whose source was deleted is orphaned: show nothing
                // rather than a column that never resolves.
                if (! $attempt || ! $attempt->quiz_id) {
                    continue;
                }

                $quizId = (int) $attempt->quiz_id;
                $current = $bestQuizByStudent[$quizId][$grade->student_id] ?? null;

                if ($current === null || $this->rank($grade) > $this->rank($current)) {
                    $bestQuizByStudent[$quizId][$grade->student_id] = $grade;
                }
            } elseif ($grade->source_type === AssignmentSubmission::class) {
                $submission = $grade->source;

                if (! $submission || ! $submission->assignment_id) {
                    continue;
                }

                $assignmentByStudent[(int) $submission->assignment_id][$grade->student_id] = $grade;
            }
        }

        return [$bestQuizByStudent, $assignmentByStudent];
    }

    /**
     * @param  array<int, array{key: string, type: string, id: int, title: string}>  $assessments
     * @param  array<int, array<int, Grade>>  $bestQuizByStudent
     * @param  array<int, array<int, Grade>>  $assignmentByStudent
     */
    private function studentRow(Enrollment $enrollment, \Illuminate\Support\Collection $assessments, array $bestQuizByStudent, array $assignmentByStudent): array
    {
        $cells = [];
        $graded = [];

        foreach ($assessments as $assessment) {
            $grade = $assessment['type'] === 'quiz'
                ? ($bestQuizByStudent[$assessment['id']][$enrollment->student_id] ?? null)
                : ($assignmentByStudent[$assessment['id']][$enrollment->student_id] ?? null);

            if ($grade === null) {
                $cells[$assessment['key']] = null;

                continue;
            }

            $cells[$assessment['key']] = [
                'score' => (float) $grade->score,
                'max_score' => (float) $grade->max_score,
                'percentage' => (float) $grade->percentage,
            ];
            $graded[] = (float) $grade->percentage;
        }

        $student = $enrollment->student;

        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'graded_count' => count($graded),
            'course_percentage' => $graded === [] ? null : round(array_sum($graded) / count($graded), 2),
            'cells' => $cells,
        ];
    }

    /**
     * The class average of a column: the mean over the students who have a grade
     * in it, so a column is not dragged down by the students who have not sat it.
     *
     * @param  array<int, string>  $keys
     * @param  \Illuminate\Support\Collection<int, array{cells: array<string, array{percentage: float}|null>}>  $students
     * @return array<string, float>
     */
    private function assessmentAverages(array $keys, \Illuminate\Support\Collection $students): array
    {
        $averages = [];

        foreach ($keys as $key) {
            // A 0% is a real grade; an ungraded cell is absent. Only the latter
            // may be dropped, so the filter keeps zeroes.
            $percentages = $students
                ->map(fn (array $student): ?float => $student['cells'][$key]['percentage'] ?? null)
                ->filter(fn (?float $percentage): bool => $percentage !== null)
                ->values();

            if ($percentages->isNotEmpty()) {
                $averages[$key] = round($percentages->avg(), 2);
            }
        }

        return $averages;
    }

    /**
     * The higher of percentage-first, absolute-score-second, so a better result
     * on an equal-percentage-but-larger-score attempt wins the cell.
     */
    private function rank(Grade $grade): float
    {
        return (float) $grade->percentage * 1000000 + (float) $grade->score;
    }
}
