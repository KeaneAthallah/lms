<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\LessonType;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\QuizAttempt;
use App\Models\User;
use App\SubmissionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LearningInsightService
{
    /**
     * Quiz scores below this threshold are treated as needing a high-priority review.
     */
    public const QUIZ_HIGH_REVIEW_THRESHOLD = 60;

    /**
     * Quiz scores in [QUIZ_HIGH_REVIEW_THRESHOLD, this) are treated as needing a medium-priority review.
     */
    public const QUIZ_MEDIUM_REVIEW_THRESHOLD = 75;

    /**
     * Average assessment score (0-100) required before a student is considered ready for an upcoming quiz.
     */
    public const QUIZ_READY_THRESHOLD = 75;

    /**
     * Build the full set of learning insights for a student.
     *
     * Every recommendation is derived deterministically from the student's own progress,
     * quiz attempts, and grading data. No fabricated or external-AI data is used.
     *
     * @return array<string, mixed>
     */
    public function insightsFor(User $student): array
    {
        $enrollments = $this->loadActiveEnrollments($student);
        $attemptsByQuiz = $this->loadQuizAttempts($student);
        $submissionsByAssignment = $this->loadGradedSubmissions($student);

        $curricula = $this->buildCurricula($enrollments);

        $reviews = $this->buildReviews($curricula, $attemptsByQuiz, $submissionsByAssignment);
        $recommendations = $this->buildRecommendations($curricula);
        $quizReadiness = $this->buildQuizReadiness($curricula, $attemptsByQuiz, $submissionsByAssignment);
        $momentum = $this->buildMomentum($student, $enrollments);
        $focus = $this->pickFocus($reviews, $recommendations);
        [$studyPlan, $studyPlanTotalMinutes] = $this->buildStudyPlan($reviews, $recommendations, $quizReadiness);

        return [
            'summary' => [
                'active_courses' => $enrollments->count(),
                'reviews_count' => count($reviews),
                'recommendations_count' => count($recommendations),
                'quizzes_upcoming_count' => count($quizReadiness),
                'momentum' => $momentum,
                'study_plan_total_minutes' => $studyPlanTotalMinutes,
            ],
            'focus' => $focus,
            'reviews' => $reviews,
            'recommendations' => $recommendations,
            'quiz_readiness' => $quizReadiness,
            'study_plan' => $studyPlan,
        ];
    }

    /**
     * @return Collection<int, Enrollment>
     */
    protected function loadActiveEnrollments(User $student): Collection
    {
        return Enrollment::query()
            ->where('student_id', $student->id)
            ->where('status', EnrollmentStatus::Active->value)
            ->with([
                'course' => fn ($query) => $query->with([
                    'sections' => fn ($query) => $query->orderBy('sort_order')->with([
                        'lessons' => fn ($query) => $query->published()->orderBy('sort_order')->with([
                            'quiz' => fn ($query) => $query->withCount('questions'),
                            'assignment:id,course_id,title,max_score',
                            'progress' => fn ($query) => $query->where('student_id', $student->id),
                        ]),
                    ]),
                ]),
            ])
            ->orderByDesc('last_accessed_at')
            ->get();
    }

    /**
     * @return Collection<int, QuizAttempt>
     */
    protected function loadQuizAttempts(User $student): Collection
    {
        return QuizAttempt::query()
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->get(['id', 'quiz_id', 'score_percentage', 'passed', 'submitted_at'])
            ->groupBy('quiz_id');
    }

    /**
     * @return Collection<int, AssignmentSubmission>
     */
    protected function loadGradedSubmissions(User $student): Collection
    {
        return AssignmentSubmission::query()
            ->where('student_id', $student->id)
            ->where('status', SubmissionStatus::Graded->value)
            ->orderByDesc('graded_at')
            ->with('assignment:id,max_score')
            ->get(['id', 'assignment_id', 'grade', 'graded_at'])
            ->groupBy('assignment_id');
    }

    /**
     * Flatten active courses into ordered, page-ready curriculum structures.
     *
     * Lessons are ordered by section sort_order then lesson sort_order, matching the
     * order students see in the Learn view.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array<int, array{course: Course, lessons: Collection<int, Lesson>, payloads: array<int, array<string, mixed>>}>
     */
    protected function buildCurricula(Collection $enrollments): array
    {
        $curricula = [];

        foreach ($enrollments as $enrollment) {
            $lessons = collect();

            foreach ($enrollment->course->sections as $section) {
                foreach ($section->lessons as $lesson) {
                    $lessons->push($lesson);
                }
            }

            $payloads = [];
            foreach ($lessons as $lesson) {
                $payloads[$lesson->id] = $this->lessonPayload($lesson, $enrollment->course);
            }

            $curricula[] = [
                'course' => $enrollment->course,
                'lessons' => $lessons,
                'payloads' => $payloads,
            ];
        }

        return $curricula;
    }

    /**
     * @param  array<int, array{course: Course, lessons: Collection<int, Lesson>, payloads: array<int, array<string, mixed>>}>  $curricula
     * @param  Collection<int, QuizAttempt>  $attemptsByQuiz
     * @param  Collection<int, AssignmentSubmission>  $submissionsByAssignment
     * @return array<int, array<string, mixed>>
     */
    protected function buildReviews(array $curricula, Collection $attemptsByQuiz, Collection $submissionsByAssignment): array
    {
        $reviews = [];

        foreach ($curricula as $curriculum) {
            foreach ($curriculum['lessons'] as $lesson) {
                if (! $this->isCompletedByStudent($lesson)) {
                    continue;
                }

                $assessment = $this->lessonAssessment($lesson, $attemptsByQuiz, $submissionsByAssignment);

                if ($assessment === null) {
                    continue;
                }

                $score = round($assessment['score'], 1);

                if ($score < self::QUIZ_HIGH_REVIEW_THRESHOLD) {
                    $priority = 'high';
                } elseif ($score < self::QUIZ_MEDIUM_REVIEW_THRESHOLD) {
                    $priority = 'medium';
                } else {
                    continue;
                }

                $reviews[] = [
                    'id' => $lesson->id,
                    'priority' => $priority,
                    'score' => $score,
                    'evidence' => $this->assessmentEvidence($assessment),
                    'reason' => $this->reviewReason($assessment),
                    'lesson' => $curriculum['payloads'][$lesson->id],
                    'cta' => $this->lessonCta($curriculum['payloads'][$lesson->id]),
                ];
            }
        }

        usort($reviews, function (array $a, array $b): int {
            return [$a['priority'] === 'high' ? 0 : 1, $a['score']]
                <=> [$b['priority'] === 'high' ? 0 : 1, $b['score']];
        });

        return $reviews;
    }

    /**
     * @param  array<int, array{course: Course, lessons: Collection<int, Lesson>, payloads: array<int, array<string, mixed>>}>  $curricula
     * @return array<int, array<string, mixed>>
     */
    protected function buildRecommendations(array $curricula): array
    {
        $recommendations = [];

        foreach ($curricula as $curriculum) {
            $course = $curriculum['course'];
            $lessons = $curriculum['lessons'];

            $nextLesson = $lessons->first(fn (Lesson $lesson) => ! $this->isCompletedByStudent($lesson));

            if ($nextLesson === null) {
                continue;
            }

            $completedBefore = $lessons
                ->takeWhile(fn (Lesson $lesson) => $this->isCompletedByStudent($lesson))
                ->count();

            $reason = $completedBefore === 0
                ? "This is where you left off in \"{$course->title}\"."
                : "You have completed the preceding {$completedBefore} ".($completedBefore === 1 ? 'lesson' : 'lessons')." in \"{$course->title}\" — continue from here.";

            $recommendations[] = [
                'id' => $nextLesson->id,
                'kind' => 'continue',
                'course_id' => $course->id,
                'reason' => $reason,
                'lesson' => $curriculum['payloads'][$nextLesson->id],
                'cta' => $this->lessonCta($curriculum['payloads'][$nextLesson->id]),
            ];
        }

        return array_slice($recommendations, 0, 3);
    }

    /**
     * @param  array<int, array{course: Course, lessons: Collection<int, Lesson>, payloads: array<int, array<string, mixed>>}>  $curricula
     * @param  Collection<int, QuizAttempt>  $attemptsByQuiz
     * @param  Collection<int, AssignmentSubmission>  $submissionsByAssignment
     * @return array<int, array<string, mixed>>
     */
    protected function buildQuizReadiness(array $curricula, Collection $attemptsByQuiz, Collection $submissionsByAssignment): array
    {
        $readiness = [];

        foreach ($curricula as $curriculum) {
            $course = $curriculum['course'];
            $seenUpcomingQuiz = false;

            foreach ($curriculum['lessons'] as $index => $lesson) {
                if ($lesson->quiz_id === null || $lesson->quiz === null) {
                    continue;
                }

                if (($attemptsByQuiz->get($lesson->quiz_id, collect()))->contains(fn (QuizAttempt $attempt) => (bool) $attempt->passed)) {
                    continue;
                }

                $seenUpcomingQuiz = true;
                $quiz = $lesson->quiz;

                $precedingLessons = $curriculum['lessons']->slice(0, $index);
                $incompletePreceding = $precedingLessons->filter(fn (Lesson $preceding) => ! $this->isCompletedByStudent($preceding))->count();

                if ($incompletePreceding > 0) {
                    $state = 'not_ready';
                    $reason = "Complete the {$incompletePreceding} remaining ".($incompletePreceding === 1 ? 'lesson' : 'lessons')." in \"{$course->title}\" before attempting this quiz.";
                } else {
                    $scores = $precedingLessons
                        ->map(fn (Lesson $preceding) => $this->lessonAssessment($preceding, $attemptsByQuiz, $submissionsByAssignment))
                        ->filter()
                        ->map(fn (array $assessment) => $assessment['score']);

                    if ($scores->isEmpty()) {
                        $state = 'ready';
                        $reason = 'You have completed every lesson leading up to this quiz, and there is no assessment data to suggest gaps. You are ready to take it.';
                    } else {
                        $average = round($scores->avg(), 1);

                        if ($average >= self::QUIZ_READY_THRESHOLD) {
                            $state = 'ready';
                            $reason = "You have completed every lesson leading up to this quiz and your recent assessment average ({$this->formatPercent($average)}%) is at or above the ".self::QUIZ_READY_THRESHOLD.'% readiness threshold.';
                        } else {
                            $state = 'preparing';
                            $reason = "You have completed every lesson leading up to this quiz, but your recent assessment average ({$this->formatPercent($average)}%) is below the ".self::QUIZ_READY_THRESHOLD.'% readiness threshold. Review the flagged topics first.';
                        }
                    }
                }

                $readiness[] = [
                    'lesson_id' => $lesson->id,
                    'lesson' => $curriculum['payloads'][$lesson->id],
                    'quiz' => [
                        'id' => $quiz->id,
                        'title' => $quiz->title,
                        'passing_score' => (float) $quiz->passing_score,
                        'time_limit_minutes' => $quiz->time_limit_minutes,
                    ],
                    'state' => $state,
                    'reason' => $reason,
                    'cta' => ['to' => "/quiz/{$quiz->id}", 'label' => 'Take quiz'],
                ];

                if ($seenUpcomingQuiz) {
                    break;
                }
            }

            if (count($readiness) >= 5) {
                break;
            }
        }

        return $readiness;
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array<string, mixed>
     */
    protected function buildMomentum(User $student, Collection $enrollments): array
    {
        $now = now();

        $completedAt = LessonProgress::where('student_id', $student->id)
            ->whereNotNull('completed_at')
            ->pluck('completed_at');

        $attemptsAt = QuizAttempt::where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->pluck('submitted_at');

        $gradedAt = AssignmentSubmission::where('student_id', $student->id)
            ->whereNotNull('graded_at')
            ->pluck('graded_at');

        $sevenDaysAgo = $now->copy()->subDays(7);

        $lessonsCompleted7d = $completedAt->filter(fn (?Carbon $date) => $date !== null && $date->gte($sevenDaysAgo))->count();
        $attemptsTaken7d = $attemptsAt->filter(fn (?Carbon $date) => $date !== null && $date->gte($sevenDaysAgo))->count();
        $submissions7d = $gradedAt->filter(fn (?Carbon $date) => $date !== null && $date->gte($sevenDaysAgo))->count();

        $streakDays = $this->activeDaysStreak($completedAt->merge($attemptsAt)->merge($gradedAt));

        $activity = $lessonsCompleted7d + $attemptsTaken7d + $submissions7d;

        if ($enrollments->isEmpty()) {
            $label = 'No activity yet';
        } elseif ($activity === 0) {
            $label = 'Getting back on track';
        } elseif ($lessonsCompleted7d >= 3 || $streakDays >= 3) {
            $label = 'Steady momentum';
        } elseif ($lessonsCompleted7d >= 1) {
            $label = 'Active';
        } else {
            $label = 'Light week';
        }

        $lastCandidates = collect([...$enrollments->pluck('last_accessed_at'), ...$completedAt, ...$attemptsAt, ...$gradedAt])
            ->filter();

        return [
            'label' => $label,
            'lessons_completed_7d' => $lessonsCompleted7d,
            'quiz_attempts_7d' => $attemptsTaken7d,
            'submissions_7d' => $submissions7d,
            'streak_days' => $streakDays,
            'last_activity_at' => $lastCandidates->max()?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviews
     * @param  array<int, array<string, mixed>>  $recommendations
     * @return array<string, mixed>|null
     */
    protected function pickFocus(array $reviews, array $recommendations): ?array
    {
        foreach ($reviews as $review) {
            if ($review['priority'] === 'high') {
                return array_merge(['type' => 'review', 'priority' => 'high'], $this->slice($review, ['lesson', 'reason', 'cta']));
            }
        }

        if ($reviews !== []) {
            $review = $reviews[0];

            return array_merge(['type' => 'review', 'priority' => 'medium'], $this->slice($review, ['lesson', 'reason', 'cta']));
        }

        if ($recommendations !== []) {
            $recommendation = $recommendations[0];

            return array_merge(['type' => 'continue'], $this->slice($recommendation, ['lesson', 'reason', 'cta']));
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviews
     * @param  array<int, array<string, mixed>>  $recommendations
     * @param  array<int, array<string, mixed>>  $quizReadiness
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected function buildStudyPlan(array $reviews, array $recommendations, array $quizReadiness): array
    {
        $items = [];

        if ($reviews !== []) {
            $review = $reviews[0];
            $items[] = $this->planItem('Review', 'lesson', $review['lesson'], $review['lesson']['estimated_minutes'], $review['lesson']['estimated'], $review['reason'], $review['cta']);
        }

        if ($recommendations !== []) {
            $recommendation = $recommendations[0];
            $items[] = $this->planItem('Continue', 'lesson', $recommendation['lesson'], $recommendation['lesson']['estimated_minutes'], $recommendation['lesson']['estimated'], $recommendation['reason'], $recommendation['cta']);
        }

        $upcomingPractice = collect($quizReadiness)
            ->first(fn (array $quiz) => $quiz['state'] === 'preparing')
            ?? collect($quizReadiness)->first(fn (array $quiz) => $quiz['state'] === 'ready');

        if ($upcomingPractice !== null) {
            $items[] = $this->planItem('Practice', 'quiz', $upcomingPractice['lesson'], $upcomingPractice['lesson']['estimated_minutes'], $upcomingPractice['lesson']['estimated'], $upcomingPractice['reason'], $upcomingPractice['cta']);
        }

        if (isset($reviews[1])) {
            $review = $reviews[1];
            $items[] = $this->planItem('Review', 'lesson', $review['lesson'], $review['lesson']['estimated_minutes'], $review['lesson']['estimated'], $review['reason'], $review['cta']);
        }

        if (isset($recommendations[1])) {
            $recommendation = $recommendations[1];
            $items[] = $this->planItem('Continue', 'lesson', $recommendation['lesson'], $recommendation['lesson']['estimated_minutes'], $recommendation['lesson']['estimated'], $recommendation['reason'], $recommendation['cta']);
        }

        $items = array_slice($items, 0, 4);

        foreach ($items as $index => $item) {
            $items[$index]['order'] = $index + 1;
        }

        $totalMinutes = (int) collect($items)->sum('minutes');

        return [$items, $totalMinutes];
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @return array<string, mixed>
     */
    protected function planItem(string $action, string $kind, array $lesson, ?int $minutes, bool $estimated, string $reason, array $cta): array
    {
        return [
            'action' => $action,
            'kind' => $kind,
            'lesson' => $lesson,
            'minutes' => $minutes ?? 5,
            'estimated' => $estimated,
            'reason' => $reason,
            'cta' => $cta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lessonPayload(Lesson $lesson, Course $course): array
    {
        [$minutes, $estimated] = $this->estimatedMinutes($lesson);

        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'type' => $lesson->type?->value ?? 'unknown',
            'completed' => $this->isCompletedByStudent($lesson),
            'duration_seconds' => $lesson->duration_seconds,
            'estimated_minutes' => $minutes,
            'estimated' => $estimated,
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
            ],
        ];
    }

    /**
     * @return array{0: int, 1: bool}
     */
    protected function estimatedMinutes(Lesson $lesson): array
    {
        if (is_numeric($lesson->duration_seconds) && (int) $lesson->duration_seconds > 0) {
            return [max(1, (int) round((int) $lesson->duration_seconds / 60)), false];
        }

        if ($lesson->quiz !== null) {
            if ($lesson->quiz->time_limit_minutes > 0) {
                return [$lesson->quiz->time_limit_minutes, false];
            }

            $questions = (int) ($lesson->quiz->questions_count ?? 0);

            return [max(5, (int) round($questions * 1.5)), true];
        }

        $defaults = [
            LessonType::Video->value => 8,
            LessonType::Text->value => 6,
            LessonType::Document->value => 6,
            LessonType::ExternalLink->value => 5,
            LessonType::Assignment->value => 20,
        ];

        $minutes = $defaults[$lesson->type?->value ?? ''] ?? 5;

        return [$minutes, true];
    }

    /**
     * Latest completed assessment for a lesson (quiz percentage or graded assignment percentage).
     *
     * @param  Collection<int, QuizAttempt>  $attemptsByQuiz
     * @param  Collection<int, AssignmentSubmission>  $submissionsByAssignment
     * @return array{score: float, source: string}|null
     */
    protected function lessonAssessment(Lesson $lesson, Collection $attemptsByQuiz, Collection $submissionsByAssignment): ?array
    {
        if ($lesson->quiz_id !== null) {
            $latest = ($attemptsByQuiz->get($lesson->quiz_id) ?? collect())->first();
            if ($latest instanceof QuizAttempt) {
                return ['score' => (float) $latest->score_percentage, 'source' => 'quiz'];
            }
        }

        if ($lesson->assignment_id !== null) {
            $latest = ($submissionsByAssignment->get($lesson->assignment_id) ?? collect())->first();
            if ($latest instanceof AssignmentSubmission && (float) $latest->assignment?->max_score > 0) {
                $percentage = ((float) $latest->grade / (float) $latest->assignment->max_score) * 100;

                return ['score' => $percentage, 'source' => 'assignment'];
            }
        }

        return null;
    }

    /**
     * @param  array{score: float, source: string}  $assessment
     */
    protected function assessmentEvidence(array $assessment): string
    {
        $label = $assessment['source'] === 'quiz' ? 'Quiz result' : 'Assignment grade';

        return $label.': '.$this->formatPercent($assessment['score']).'%';
    }

    /**
     * @param  array{score: float, source: string}  $assessment
     */
    protected function reviewReason(array $assessment): string
    {
        $score = $this->formatPercent($assessment['score']);

        if ($assessment['source'] === 'quiz') {
            if ($assessment['score'] < self::QUIZ_HIGH_REVIEW_THRESHOLD) {
                return "You completed this lesson, but your latest quiz result was {$score}% — below the ".self::QUIZ_HIGH_REVIEW_THRESHOLD.'% review threshold. Revisit the material before moving on.';
            }

            return "You completed this lesson, but your latest quiz result was {$score}%. A quick review will firm up your understanding.";
        }

        if ($assessment['score'] < self::QUIZ_HIGH_REVIEW_THRESHOLD) {
            return "You completed this lesson, but your latest assignment grade was {$score}% — below the ".self::QUIZ_HIGH_REVIEW_THRESHOLD.'% review threshold. Revisit the material before doing the assignment.';
        }

        return "You completed this lesson, but your latest assignment grade was {$score}%. A quick review will firm up your understanding.";
    }

    /**
     * @param  array<string, mixed>  $lessonPayload
     * @return array<string, string>
     */
    protected function lessonCta(array $lessonPayload): array
    {
        return [
            'to' => "/learn/{$lessonPayload['course']['slug']}/{$lessonPayload['id']}",
            'label' => 'Open lesson',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function slice(array $item, array $keys): array
    {
        return array_intersect_key($item, array_flip($keys));
    }

    protected function isCompletedByStudent(Lesson $lesson): bool
    {
        return $lesson->progress->first()?->isCompleted() ?? false;
    }

    /**
     * @param  Collection<int, Carbon|string|null>  $dates
     */
    protected function activeDaysStreak(Collection $dates): int
    {
        $days = $dates
            ->filter()
            ->map(fn (Carbon|string $date) => Carbon::parse($date)->toDateString())
            ->unique();

        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        $cursor = $days->contains($today) ? Carbon::today() : Carbon::yesterday();

        if (! $days->contains($cursor->toDateString())) {
            return 0;
        }

        $streak = 0;
        while ($days->contains($cursor->toDateString())) {
            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }

    protected function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
