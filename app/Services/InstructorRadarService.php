<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\SubmissionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds a per-student learning radar for one course, using only observable
 * LMS data. Labels are deliberately neutral and measurable (needs attention,
 * low recent activity, repeated assessment difficulty, assignment overdue,
 * strong progress). The service never infers student health, motivation,
 * aptitude, or personality.
 *
 * Every per-student number is aggregated in SQL and keyed by student id, so the
 * work this endpoint does is proportional to the size of the cohort rather than
 * to how much history that cohort has accumulated. Assigning and quizzes are
 * ordered by id so the single assignment or quiz named in a flag's evidence is
 * the same one on every request.
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

    private const RECENT_LESSON_DAYS = 7;

    private const STRONG_PROGRESS_PERCENT = 80;

    private const STRONG_PROGRESS_RECENT_LESSONS = 3;

    /**
     * @return array<string, mixed>
     */
    public function radarFor(Course $course): array
    {
        $now = now();

        $assignments = $course->assignments()->active()->orderBy('id')->get(['id', 'title', 'due_at', 'max_score']);
        $quizzes = $course->quizzes()->orderBy('id')->get(['id', 'title', 'passing_score']);
        $totalLessons = $course->lessons()->published()->count();

        $enrollments = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', '!=', EnrollmentStatus::Cancelled->value)
            ->with('student:id,name,email')
            ->orderByDesc('last_accessed_at')
            ->get(['id', 'student_id', 'progress_percent', 'enrolled_at', 'last_accessed_at', 'completed_at']);

        $studentIds = $enrollments->pluck('student_id')->unique()->values();

        $cohort = [
            'assignments' => $assignments,
            'quizzes' => $quizzes,
            'total_lessons' => $totalLessons,
            'lessons' => $this->completedLessonTotals($course, $studentIds, $now->copy()->subDays(self::RECENT_LESSON_DAYS)),
            'attempts' => $this->quizAttemptTotals($studentIds, $quizzes->pluck('id')),
            'grades' => $this->gradedSubmissionGrades($studentIds, $assignments->pluck('id')),
            'certified' => $this->certifiedStudentIds($course, $studentIds),
            'now' => $now,
        ];

        $students = $enrollments->map(fn (Enrollment $enrollment): array => $this->studentRadar($enrollment, $cohort));

        return [
            'generated_at' => $now->toIso8601String(),
            'method' => 'deterministic_rules',
            'course' => ['id' => $course->id, 'title' => $course->title, 'slug' => $course->slug],
            'students' => $students->values()->all(),
        ];
    }

    /**
     * Completed lesson counts per student, split into all-time and recent.
     *
     * @param  Collection<int, int>  $studentIds
     * @return array<int, array{completed: int, recent: int}>
     */
    private function completedLessonTotals(Course $course, Collection $studentIds, Carbon $recentCutoff): array
    {
        if ($studentIds->isEmpty()) {
            return [];
        }

        return LessonProgress::query()
            ->where('course_id', $course->id)
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('completed_at')
            ->groupBy('student_id')
            ->select('student_id')
            ->selectRaw('COUNT(*) as completed_lessons')
            ->selectRaw('SUM(CASE WHEN completed_at >= ? THEN 1 ELSE 0 END) as recent_lessons', [$recentCutoff])
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->student_id => [
                    'completed' => (int) $row->completed_lessons,
                    'recent' => (int) $row->recent_lessons,
                ],
            ])
            ->all();
    }

    /**
     * Quiz attempt totals per student: attempts made, best score overall, plus the
     * per-quiz best and below-passing counts the two quiz flags are derived from.
     *
     * @param  Collection<int, int>  $studentIds
     * @param  Collection<int, int>  $quizIds
     * @return array<int, array{attempts: int, best: float|null, best_by_quiz: array<int, float>, below_passing_by_quiz: array<int, int>}>
     */
    private function quizAttemptTotals(Collection $studentIds, Collection $quizIds): array
    {
        if ($studentIds->isEmpty() || $quizIds->isEmpty()) {
            return [];
        }

        $rows = QuizAttempt::query()
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->whereIn('quiz_attempts.student_id', $studentIds)
            ->whereIn('quiz_attempts.quiz_id', $quizIds)
            ->whereNotNull('quiz_attempts.submitted_at')
            ->groupBy('quiz_attempts.student_id', 'quiz_attempts.quiz_id')
            ->select(['quiz_attempts.student_id', 'quiz_attempts.quiz_id'])
            ->selectRaw('COUNT(*) as attempts_count')
            ->selectRaw('MAX(quiz_attempts.score_percentage) as best_percentage')
            ->selectRaw('SUM(CASE WHEN quiz_attempts.score_percentage < quizzes.passing_score THEN 1 ELSE 0 END) as below_passing_count')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $studentId = (int) $row->student_id;
            $quizId = (int) $row->quiz_id;
            $best = (float) $row->best_percentage;

            $totals[$studentId] ??= [
                'attempts' => 0,
                'best' => null,
                'best_by_quiz' => [],
                'below_passing_by_quiz' => [],
            ];

            $totals[$studentId]['attempts'] += (int) $row->attempts_count;
            $totals[$studentId]['best'] = $totals[$studentId]['best'] === null
                ? $best
                : max($totals[$studentId]['best'], $best);
            $totals[$studentId]['best_by_quiz'][$quizId] = $best;

            $belowPassing = (int) $row->below_passing_count;

            if ($belowPassing > 0) {
                $totals[$studentId]['below_passing_by_quiz'][$quizId] = $belowPassing;
            }
        }

        return $totals;
    }

    /**
     * Graded grades per student, keyed by assignment id so the overdue check can
     * ask "was this one marked?" without holding on to submission rows. The
     * unique (assignment_id, student_id) index means one grade per pair.
     *
     * @param  Collection<int, int>  $studentIds
     * @param  Collection<int, int>  $assignmentIds
     * @return array<int, array<int, float|null>>
     */
    private function gradedSubmissionGrades(Collection $studentIds, Collection $assignmentIds): array
    {
        if ($studentIds->isEmpty() || $assignmentIds->isEmpty()) {
            return [];
        }

        return AssignmentSubmission::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('assignment_id', $assignmentIds)
            ->where('status', SubmissionStatus::Graded->value)
            ->get(['student_id', 'assignment_id', 'grade'])
            ->groupBy('student_id')
            ->map(fn (Collection $submissions): array => $submissions
                ->mapWithKeys(fn (AssignmentSubmission $submission): array => [
                    (int) $submission->assignment_id => $submission->grade === null ? null : (float) $submission->grade,
                ])
                ->all())
            ->all();
    }

    /**
     * @param  Collection<int, int>  $studentIds
     * @return array<int, true>
     */
    private function certifiedStudentIds(Course $course, Collection $studentIds): array
    {
        if ($studentIds->isEmpty()) {
            return [];
        }

        return Certificate::query()
            ->where('course_id', $course->id)
            ->whereIn('student_id', $studentIds)
            ->distinct()
            ->pluck('student_id', 'student_id')
            ->map(fn (): bool => true)
            ->all();
    }

    /**
     * @param  array{
     *     assignments: Collection<int, Assignment>,
     *     quizzes: Collection<int, Quiz>,
     *     total_lessons: int,
     *     lessons: array<int, array{completed: int, recent: int}>,
     *     attempts: array<int, array{attempts: int, best: float|null, best_by_quiz: array<int, float>, below_passing_by_quiz: array<int, int>}>,
     *     grades: array<int, array<int, float|null>>,
     *     certified: array<int, true>,
     *     now: Carbon
     * }  $cohort
     * @return array<string, mixed>
     */
    private function studentRadar(Enrollment $enrollment, array $cohort): array
    {
        $student = $enrollment->student;
        $now = $cohort['now'];
        $lessons = $cohort['lessons'][$student->id] ?? ['completed' => 0, 'recent' => 0];
        $attempts = $cohort['attempts'][$student->id] ?? [
            'attempts' => 0,
            'best' => null,
            'best_by_quiz' => [],
            'below_passing_by_quiz' => [],
        ];
        $grades = $cohort['grades'][$student->id] ?? [];
        $hasCertificate = isset($cohort['certified'][$student->id]);

        $flags = [];
        $flag = fn (string $kind, string $label, string $evidence) => ['kind' => $kind, 'label' => $label, 'evidence' => $evidence];

        // Repeated assessment difficulty: two or more attempts below passing on the same quiz.
        $repeatedQuiz = $cohort['quizzes']->first(
            fn (Quiz $quiz): bool => ($attempts['below_passing_by_quiz'][$quiz->id] ?? 0) >= 2
        );

        if ($repeatedQuiz !== null) {
            $flags[] = $flag(
                self::FLAG_REPEATED_DIFFICULTY,
                'Repeated assessment difficulty',
                sprintf(
                    'Quiz "%s" has been attempted %d times below the passing score.',
                    $repeatedQuiz->title,
                    $attempts['below_passing_by_quiz'][$repeatedQuiz->id]
                )
            );
        }

        // Needs attention: any quiz best below passing, or a graded assignment below 60%.
        $quizBelowPassing = $cohort['quizzes']->first(
            fn (Quiz $quiz): bool => (float) ($attempts['best_by_quiz'][$quiz->id] ?? 100) < (float) $quiz->passing_score
        );
        $lowAssignment = $this->firstLowGradeAssignment($cohort['assignments'], $grades);

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
                sprintf('Latest grade on assignment "%s" is below %d%%.', $lowAssignment->title, self::LOW_ASSIGNMENT_PERCENT)
            );
        }

        // Assignment overdue: an active assignment is past due without a graded submission.
        $overdue = $cohort['assignments']->first(
            fn (Assignment $assignment): bool => $assignment->due_at !== null
                && $assignment->due_at->lt($now)
                && ! isset($grades[$assignment->id])
        );

        if ($overdue !== null) {
            $flags[] = $flag(
                self::FLAG_ASSIGNMENT_OVERDUE,
                'Assignment overdue',
                sprintf('Assignment "%s" was due on %s.', $overdue->title, $overdue->due_at->format('M j'))
            );
        }

        // Strong progress: certificate, 80%+ progress, or 3+ lessons completed in the last 7 days.
        $recentLessons = $lessons['recent'];

        if ($hasCertificate || $enrollment->progress_percent >= self::STRONG_PROGRESS_PERCENT || $recentLessons >= self::STRONG_PROGRESS_RECENT_LESSONS) {
            $evidence = $hasCertificate
                ? 'Completed the course and earned a certificate.'
                : ($recentLessons >= self::STRONG_PROGRESS_RECENT_LESSONS
                    ? sprintf('Completed %d lessons in the last %d days.', $recentLessons, self::RECENT_LESSON_DAYS)
                    : sprintf('Reached %d%% course progress.', $enrollment->progress_percent));

            $flags[] = $flag(self::FLAG_STRONG_PROGRESS, 'Strong progress', $evidence);
        }

        // Low recent activity: active (not completed) enrollment idle for 14+ days.
        $isComplete = $enrollment->isCompleted() || $enrollment->progress_percent >= 100 || $hasCertificate;
        $lastAccess = $enrollment->last_accessed_at;
        $inactiveDays = $lastAccess === null ? null : (int) $lastAccess->diffInDays($now);

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
            'last_accessed_at' => $lastAccess?->toIso8601String(),
            'progress_percent' => (int) $enrollment->progress_percent,
            'stats' => [
                'completed_lessons' => $lessons['completed'],
                'total_lessons' => $cohort['total_lessons'],
                'quiz_attempts_count' => $attempts['attempts'],
                'best_quiz_percentage' => $attempts['best'] === null ? null : (float) round($attempts['best'], 1),
                'graded_assignments' => count($grades),
                'assignments_overdue' => $overdue === null ? 0 : 1,
                'has_certificate' => $hasCertificate,
            ],
            'focus' => $this->pickFocus($flags),
            'flags' => array_values($flags),
        ];
    }

    /**
     * The first active assignment in course order whose graded grade is below the
     * threshold, so the evidence names a stable assignment.
     *
     * @param  Collection<int, Assignment>  $assignments
     * @param  array<int, float|null>  $grades
     */
    private function firstLowGradeAssignment(Collection $assignments, array $grades): ?Assignment
    {
        if ($grades === []) {
            return null;
        }

        return $assignments->first(fn (Assignment $assignment): bool => isset($grades[$assignment->id])
            && $this->assignmentPercent($grades[$assignment->id], (float) $assignment->max_score) < self::LOW_ASSIGNMENT_PERCENT);
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

    protected function assignmentPercent(?float $grade, ?float $maxScore): float
    {
        return $maxScore !== null && $maxScore > 0 ? ($grade ?? 0.0) / $maxScore * 100 : 0.0;
    }
}
