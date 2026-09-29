<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradebookCategory;
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
 * Assessments may be grouped into weighted categories. The course percentage is
 * the weighted mean of the buckets the student has a grade in: within a bucket
 * their score is the mean of its graded cells, that mean is multiplied by the
 * bucket's weight, and the weighted sum is divided by the weight actually
 * applied. An untaken assessment is a dash that counts for nothing (not a zero,
 * so it does not drag a bucket - or the course grade - down). A course with no
 * categories is a single uncategorized bucket of weight 1, which reproduces the
 * simple mean exactly.
 */
class GradebookService
{
    /** The bucket an assessment with no category lands in. */
    public const UNCATEGORIZED = 'uncategorized';

    public function courseGradebook(Course $course): array
    {
        $assessments = $this->assessments($course);

        $categories = $this->categories($course, $assessments);

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

        $bucketKeys = collect($categories)->pluck('key')->all();
        $weights = collect($categories)->mapWithKeys(fn (array $category): array => [$category['key'] => $category['weight']])->all();

        $students = $enrollments->map(fn (Enrollment $enrollment): array => $this->studentRow(
            $enrollment,
            $assessments,
            $bucketKeys,
            $weights,
            $bestQuizByStudent,
            $assignmentByStudent,
        ));

        $averages = $this->assessmentAverages($assessments->pluck('key')->all(), $students);

        $categories = $this->withAverages($categories, $students);

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'categories' => $categories,
            'assessments' => $assessments->map(fn (array $assessment): array => [
                'key' => $assessment['key'],
                'type' => $assessment['type'],
                'id' => $assessment['id'],
                'title' => $assessment['title'],
                'category_id' => $assessment['category_id'],
                'category_key' => $assessment['category_key'],
                'average' => $averages[$assessment['key']] ?? null,
            ])->values()->all(),
            'students' => $students->values()->all(),
        ];
    }

    /**
     * Every assessment column, quizzes and assignments together, grouped by
     * gradebook category (in the category's order, uncategorized last) and by
     * title within a group. Each carries its category so a column maps back to
     * its quiz or assignment, its bucket, and its id without parsing the key.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     key: string,
     *     type: string,
     *     id: int,
     *     title: string,
     *     category_id: int|null,
     *     category_key: string,
     *     group: int,
     * }>
     */
    private function assessments(Course $course): \Illuminate\Support\Collection
    {
        $categoryPositions = $course->gradebookCategories
            ->mapWithKeys(fn (GradebookCategory $category, int $index): array => [$category->id => $index]);
        $uncategorized = $categoryPositions->count();

        $toColumn = function (string $type, int $id, string $title, ?int $categoryId) use ($categoryPositions, $uncategorized): array {
            $position = $categoryId !== null
                ? $categoryPositions->get($categoryId, $uncategorized)
                : $uncategorized;

            return [
                'key' => "{$type}-{$id}",
                'type' => $type,
                'id' => $id,
                'title' => $title,
                'category_id' => $categoryId,
                'category_key' => $categoryId !== null
                    ? "category-{$categoryId}"
                    : self::UNCATEGORIZED,
                'group' => $position,
            ];
        };

        return collect()
            ->concat($course->quizzes()->get(['id', 'title', 'category_id'])->map(
                fn ($quiz): array => $toColumn('quiz', (int) $quiz->id, $quiz->title, $quiz->category_id !== null ? (int) $quiz->category_id : null),
            ))
            ->concat($course->assignments()->get(['id', 'title', 'category_id'])->map(
                fn ($assignment): array => $toColumn('assignment', (int) $assignment->id, $assignment->title, $assignment->category_id !== null ? (int) $assignment->category_id : null),
            ))
            ->sortBy(fn (array $assessment): array => [$assessment['group'], strtolower($assessment['title'])])
            ->values();
    }

    /**
     * The course's gradebook categories (plus an implicit uncategorized bucket,
     * only when an assessment actually sits outside every defined category), each
     * with the number of columns it holds.
     *
     * @param  \Illuminate\Support\Collection<int, array{category_key: string}>  $assessments
     * @return array<int, array{key: string, id: int|null, name: string, weight: float, assessment_count: int}>
     */
    private function categories(Course $course, \Illuminate\Support\Collection $assessments): array
    {
        $columnsPerKey = $assessments->groupBy('category_key')->map->count()->all();

        $categories = $course->gradebookCategories->map(fn (GradebookCategory $category): array => [
            'key' => "category-{$category->id}",
            'id' => (int) $category->id,
            'name' => $category->name,
            'weight' => (float) $category->weight,
            'assessment_count' => $columnsPerKey["category-{$category->id}"] ?? 0,
        ])->all();

        if (isset($columnsPerKey[self::UNCATEGORIZED])) {
            $categories[] = [
                'key' => self::UNCATEGORIZED,
                'id' => null,
                'name' => 'Uncategorized',
                'weight' => 1.0,
                'assessment_count' => $columnsPerKey[self::UNCATEGORIZED],
            ];
        }

        return $categories;
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
     * @param  array<int, string>  $bucketKeys
     * @param  array<string, float>  $weights
     * @param  array<int, array<int, Grade>>  $bestQuizByStudent
     * @param  array<int, array<int, Grade>>  $assignmentByStudent
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     graded_count: int,
     *     course_percentage: float|null,
     *     category_percentages: array<string, float|null>,
     *     cells: array<string, array{score: float, max_score: float, percentage: float}|null>,
     * }
     */
    private function studentRow(Enrollment $enrollment, \Illuminate\Support\Collection $assessments, array $bucketKeys, array $weights, array $bestQuizByStudent, array $assignmentByStudent): array
    {
        $cells = [];
        $graded = 0;
        $bucketTotals = [];
        $bucketCounts = [];

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

            $graded++;

            $bucketTotals[$assessment['category_key']] = ($bucketTotals[$assessment['category_key']] ?? 0) + (float) $grade->percentage;
            $bucketCounts[$assessment['category_key']] = ($bucketCounts[$assessment['category_key']] ?? 0) + 1;
        }

        // A 0% is a real grade; an empty bucket is not. Unset buckets stay null,
        // so the frontend can render them as dashes rather than zeroes.
        $bucketPercentages = [];
        foreach ($bucketKeys as $key) {
            $bucketPercentages[$key] = isset($bucketCounts[$key])
                ? round($bucketTotals[$key] / $bucketCounts[$key], 2)
                : null;
        }

        // The weighted mean of the buckets that actually have a grade, over the
        // weight of just those buckets: a bucket the student never touched does
        // not drag the course grade (or the denominator) down.
        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($bucketPercentages as $key => $percentage) {
            if ($percentage === null) {
                continue;
            }

            $weight = $weights[$key];
            $numerator += $weight * $percentage;
            $denominator += $weight;
        }

        $student = $enrollment->student;

        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'graded_count' => $graded,
            'course_percentage' => $graded > 0 && $denominator > 0 ? round($numerator / $denominator, 2) : null,
            'category_percentages' => $bucketPercentages,
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
     * Add the class average of each bucket, over the students who have a grade
     * in it (the same rule as a single column).
     *
     * @param  array<int, array{key: string}>  $categories
     * @param  \Illuminate\Support\Collection<int, array{category_percentages: array<string, float|null>}>  $students
     * @return array<int, array{key: string, id: int|null, name: string, weight: float, assessment_count: int, average: float|null}>
     */
    private function withAverages(array $categories, \Illuminate\Support\Collection $students): array
    {
        return array_map(function (array $category) use ($students): array {
            $percentages = $students
                ->map(fn (array $student): ?float => $student['category_percentages'][$category['key']] ?? null)
                ->filter(fn (?float $percentage): bool => $percentage !== null)
                ->values();

            return [
                ...$category,
                'average' => $percentages->isNotEmpty() ? round($percentages->avg(), 2) : null,
            ];
        }, $categories);
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
