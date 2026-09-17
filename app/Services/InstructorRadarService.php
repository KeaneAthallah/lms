<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\QuizAttempt;

/**
 * Builds a per-student learning radar for one course, using only observable
 * LMS data. Labels are deliberately neutral and measurable (needs attention,
 * low recent activity, repeated assessment difficulty, assignment overdue,
 * strong progress). The service never infers student health, motivation,
 * aptitude, or personality.
 */
class InstructorRadarService
{
    private const FLAG_NEEDS_ATTENTION = 'needs_attention';

    private const FLAG_REPEATED_DIFFICULTY = 'repeated_assessment_difficulty';

    private const FLAG_ASSIGNMENT_OVERDUE = 'assignment_overdue';

    private const FLAG_LOW_ACTIVITY = 'low_recent_activity';

    private const FLAG_STRONG_PROGRESS = 'strong_progress';

    private const LOW_ACTIVITY_DAYS = 14;

    private const LOW_ASSIGNMENT_PERCENT = 60;

    /**
     * @return array<string, mixed>
     */
    public function radarFor(Course $course): array
    {
        $assignments = $course->assignments()->active()->get(['id', 'title', 'due_at']);
        $assignmentIds = $assignments->pluck('id');
        $quizzes = $course->quizzes()->get(['id', 'title', 'passing_score']);
        $quizIds = $quizzes->pluck('id');
        $totalLessons = $course->lessons()->published()->count();

        $enrollments = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', '!=', EnrollmentStatus::Cancelled->value)
            ->with([
                'student:id,name,email',
                'student.lessonProgress' => fn ($query) => $query->where('course_id', $course->id),
                'student.quizAttempts' => fn ($query) => $query->whereIn('quiz_id', $quizIds)->whereNotNull('submitted_at'),
                'student.assignmentSubmissions' => fn ($query) => $query->whereIn('assignment_id', $assignmentIds)->with('assignment:id,title,max_score'),
                'student.certificates' => fn ($query) => $query->where('course_id', $course->id),
            ])
            ->orderByDesc('last_accessed_at')
            ->get(['id', 'student_id', 'progress_percent', 'enrolled_at', 'last_accessed_at', 'completed_at']);

        $students = $enrollments->map(function (Enrollment $enrollment) use ($assignments, $quizzes, $totalLessons): array {
            $student = $enrollment->student;
            $progress = $student->lessonProgress;
            $attempts = $student->quizAttempts;
            $submissions = $student->assignmentSubmissions;
            $hasCertificate = $student->certificates->isNotEmpty();

            $completedLessons = $progress->filter(fn ($row) => $row->isCompleted())->count();
            $completed7d = $progress->filter(fn ($row) => $row->isCompleted() && $row->completed_at->gte(now()->subDays(7)))->count();

            $gradedAssignments = $submissions->filter(fn ($row) => $row->isGraded());
            $lowAssignments = $gradedAssignments->filter(fn ($row) => $this->assignmentPercent($row) < self::LOW_ASSIGNMENT_PERCENT);

            $flags = [];
            $flag = fn (string $kind, string $label, string $evidence) => ['kind' => $kind, 'label' => $label, 'evidence' => $evidence];

            // Repeated assessment difficulty: two or more attempts below passing on the same quiz.
            if ($quizzes->isNotEmpty()) {
                $passingByQuiz = $quizzes->pluck('passing_score', 'id');
                $belowByQuiz = $attempts
                    ->groupBy('quiz_id')
                    ->map(fn ($rows) => $rows->filter(fn (QuizAttempt $attempt) => (float) $attempt->score_percentage < (float) ($passingByQuiz[$attempt->quiz_id] ?? 0))->count())
                    ->filter(fn (int $count) => $count >= 2);

                if ($belowByQuiz->isNotEmpty()) {
                    $repeatedQuiz = $quizzes->first(fn ($quiz) => (int) $quiz->id === (int) $belowByQuiz->keys()->first());
                    $flags[] = $flag(
                        self::FLAG_REPEATED_DIFFICULTY,
                        'Repeated assessment difficulty',
                        sprintf(
                            'Quiz "%s" has been attempted %d times below the passing score.',
                            $repeatedQuiz?->title ?? 'this lesson',
                            $belowByQuiz->first()
                        )
                    );
                }
            }

            // Needs attention: any quiz best below passing, or a graded assignment below 60%.
            $bestByQuiz = $attempts->groupBy('quiz_id')->map(fn ($rows) => $rows->max('score_percentage'));
            $quizBelowPassing = $quizzes->first(fn ($quiz) => (float) ($bestByQuiz[$quiz->id] ?? 100) < (float) $quiz->passing_score);
            $lowAssignment = $lowAssignments->first();

            if ($quizBelowPassing !== null) {
                $flags[] = $flag(
                    self::FLAG_NEEDS_ATTENTION,
                    'Needs attention',
                    sprintf('Best result on quiz "%s" is below the passing score.', $quizBelowPassing->title)
                );
            } elseif ($lowAssignment !== null) {
                $flags[] = $flag(
                    self::FLAG_NEEDS_ATTENTION,
                    'Needs attention',
                    sprintf('Latest grade on assignment "%s" is below %d%%.', $lowAssignment->assignment?->title ?? 'this assignment', self::LOW_ASSIGNMENT_PERCENT)
                );
            }

            // Assignment overdue: an active assignment is past due without a graded submission.
            $overdue = $assignments
                ->filter(fn (Assignment $assignment) => $assignment->due_at !== null && $assignment->due_at->lt(now()))
                ->filter(fn (Assignment $assignment) => ! $submissions->contains(fn ($row) => (int) $row->assignment_id === (int) $assignment->id && $row->isGraded()))
                ->first();

            if ($overdue !== null) {
                $flags[] = $flag(
                    self::FLAG_ASSIGNMENT_OVERDUE,
                    'Assignment overdue',
                    sprintf('Assignment "%s" was due on %s.', $overdue->title, $overdue->due_at->format('M j'))
                );
            }

            // Strong progress: certificate, 80%+ progress, or 3+ lessons completed in the last 7 days.
            if ($hasCertificate || $enrollment->progress_percent >= 80 || $completed7d >= 3) {
                $evidence = $hasCertificate
                    ? 'Completed the course and earned a certificate.'
                    : ($completed7d >= 3
                        ? sprintf('Completed %d lessons in the last 7 days.', $completed7d)
                        : sprintf('Reached %d%% course progress.', $enrollment->progress_percent));

                $flags[] = $flag(self::FLAG_STRONG_PROGRESS, 'Strong progress', $evidence);
            }

            // Low recent activity: active (not completed) enrollment idle for 14+ days.
            $isComplete = $enrollment->isCompleted() || $enrollment->progress_percent >= 100 || $hasCertificate;
            $lastAccess = $enrollment->last_accessed_at;
            $inactiveDays = $lastAccess === null ? null : (int) $lastAccess->diffInDays(now());

            if (! $isComplete && ($lastAccess === null || $inactiveDays >= self::LOW_ACTIVITY_DAYS)) {
                $flags[] = $flag(
                    self::FLAG_LOW_ACTIVITY,
                    'Low recent activity',
                    $lastAccess === null
                        ? 'No recorded course activity yet.'
                        : sprintf('No recorded course activity in the last %d days.', $inactiveDays)
                );
            }

            return [
                'student' => ['id' => $student->id, 'name' => $student->name, 'email' => $student->email],
                'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                'last_accessed_at' => $enrollment->last_accessed_at?->toIso8601String(),
                'progress_percent' => (int) $enrollment->progress_percent,
                'stats' => [
                    'completed_lessons' => $completedLessons,
                    'total_lessons' => $totalLessons,
                    'quiz_attempts_count' => $attempts->count(),
                    'best_quiz_percentage' => $attempts->isEmpty() ? null : (float) round($attempts->max('score_percentage'), 1),
                    'graded_assignments' => $gradedAssignments->count(),
                    'assignments_overdue' => $overdue === null ? 0 : 1,
                    'has_certificate' => $hasCertificate,
                ],
                'focus' => $this->pickFocus($flags),
                'flags' => array_values($flags),
            ];
        });

        return [
            'generated_at' => now()->toIso8601String(),
            'method' => 'deterministic_rules',
            'course' => ['id' => $course->id, 'title' => $course->title, 'slug' => $course->slug],
            'students' => $students->values()->all(),
        ];
    }

    /**
     * @param  array<int, array{kind: string, label: string, evidence: string}>  $flags
     * @return array{kind: string, label: string, evidence: string}|null
     */
    private function pickFocus(array $flags): ?array
    {
        $priority = [
            self::FLAG_REPEATED_DIFFICULTY => 0,
            self::FLAG_NEEDS_ATTENTION => 1,
            self::FLAG_ASSIGNMENT_OVERDUE => 2,
            self::FLAG_LOW_ACTIVITY => 3,
            self::FLAG_STRONG_PROGRESS => 4,
        ];

        usort($flags, fn (array $a, array $b) => $priority[$a['kind']] <=> $priority[$b['kind']]);

        return $flags[0] ?? null;
    }

    protected function assignmentPercent($submission): float
    {
        $max = (float) ($submission->assignment?->max_score ?? 0);

        return $max > 0 ? ((float) $submission->grade / $max) * 100 : 0.0;
    }
}
