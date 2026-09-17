<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes concept mastery from observable learning evidence.
 *
 * A "concept" is a course section. Mastery blends, per section:
 *  - the average of each quiz's single best recent attempt (60% weight), and
 *  - the share of that section's lessons the student has completed (40% weight).
 *
 * Until a student is assessed on a section, only the completion component is
 * counted and the concept is reported as "unassessed" - the platform never
 * claims mastery without evidence.
 */
class MasteryCalculator
{
    public const STATUS_UNASSESSED = 'unassessed';

    public const STATUS_REVIEW = 'review';

    public const STATUS_BUILDING = 'building';

    public const STATUS_MASTERED = 'mastered';

    private const ASSESSMENT_WEIGHT = 0.6;

    private const COMPLETION_WEIGHT = 0.4;

    /**
     * @return array<string, mixed>
     */
    public function masteryFor(User $student): array
    {
        $enrollments = $this->loadEnrollments($student);
        $attempts = $this->loadQuizAttempts($student);

        return $this->build($enrollments, $attempts);
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, Collection<int, QuizAttempt>>  $attemptsByQuiz
     * @return array{overall_percent: int, courses: array<int, array<string, mixed>>}
     */
    public function build(Collection $enrollments, Collection $attemptsByQuiz): array
    {
        $courses = [];
        $masteryValues = [];

        foreach ($enrollments as $enrollment) {
            $course = $enrollment->course;
            $concepts = [];

            foreach ($course->sections as $section) {
                $lessons = $section->lessons;

                if ($lessons->isEmpty()) {
                    continue;
                }

                [$assessmentScore, $lastAttemptAt, $quizzes] = $this->sectionAssessment($lessons, $attemptsByQuiz);

                $completed = $lessons->filter(fn (Lesson $lesson) => $this->isCompletedByStudent($lesson))->count();
                $completionPct = round($completed / $lessons->count() * 100, 1);

                if ($assessmentScore !== null) {
                    $mastery = round(self::ASSESSMENT_WEIGHT * $assessmentScore + self::COMPLETION_WEIGHT * $completionPct, 1);
                    $status = $this->statusFor($mastery);
                } else {
                    $mastery = round(self::COMPLETION_WEIGHT * $completionPct, 1);
                    $status = self::STATUS_UNASSESSED;
                }

                $nextLesson = $lessons->first(fn (Lesson $lesson) => ! $this->isCompletedByStudent($lesson));

                $masteryValues[] = $mastery;

                $concepts[] = [
                    'section_id' => $section->id,
                    'concept' => $section->title,
                    'course' => [
                        'id' => $course->id,
                        'title' => $course->title,
                        'slug' => $course->slug,
                    ],
                    'lessons_total' => $lessons->count(),
                    'lessons_completed' => $completed,
                    'completion_pct' => (int) round($completionPct),
                    'assessment_score' => $assessmentScore,
                    'quizzes' => $quizzes->map(fn (Quiz $quiz) => [
                        'id' => $quiz->id,
                        'title' => $quiz->title,
                        'best_score_percentage' => $this->bestScore($quiz, $attemptsByQuiz),
                    ])->values()->all(),
                    'mastery_percent' => (int) round($mastery),
                    'status' => $status,
                    'last_attempt_at' => $lastAttemptAt?->toIso8601String(),
                    'next_lesson_id' => $nextLesson?->id,
                ];
            }

            $courses[] = [
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                ],
                'concepts' => $concepts,
            ];
        }

        return [
            'overall_percent' => $masteryValues === [] ? 0 : (int) round(collect($masteryValues)->avg()),
            'courses' => $courses,
        ];
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     * @param  Collection<int, Collection<int, QuizAttempt>>  $attemptsByQuiz
     * @return array{0: float|null, 1: Carbon|null, 2: Collection<int, Quiz>}
     */
    private function sectionAssessment(Collection $lessons, Collection $attemptsByQuiz): array
    {
        $scores = collect();
        $quizzes = collect();
        $lastAttemptAt = null;

        foreach ($lessons as $lesson) {
            if ($lesson->quiz_id === null || $lesson->quiz === null) {
                continue;
            }

            $quiz = $lesson->quiz;
            $quizzes->push($quiz);

            $attempts = $attemptsByQuiz->get($quiz->id) ?? collect();

            if ($attempts->isEmpty()) {
                continue;
            }

            $bestRecent = $attempts->take(2)->max(fn (QuizAttempt $attempt) => (float) $attempt->score_percentage);

            if ($bestRecent !== null) {
                $scores->push($bestRecent);
            }

            $latest = $attempts->first();

            if ($latest?->submitted_at !== null && ($lastAttemptAt === null || $latest->submitted_at->gt($lastAttemptAt))) {
                $lastAttemptAt = $latest->submitted_at;
            }
        }

        $assessmentScore = $scores->isEmpty() ? null : round((float) $scores->avg(), 1);

        return [$assessmentScore, $lastAttemptAt, $quizzes];
    }

    /**
     * @param  Collection<int, Collection<int, QuizAttempt>>  $attemptsByQuiz
     */
    private function bestScore(Quiz $quiz, Collection $attemptsByQuiz): ?float
    {
        $attempt = ($attemptsByQuiz->get($quiz->id) ?? collect())->first();

        return $attempt?->score_percentage !== null ? round((float) $attempt->score_percentage, 1) : null;
    }

    protected function statusFor(float $mastery): string
    {
        return match (true) {
            $mastery >= LearningInsightService::QUIZ_READY_THRESHOLD => self::STATUS_MASTERED,
            $mastery >= LearningInsightService::QUIZ_HIGH_REVIEW_THRESHOLD => self::STATUS_BUILDING,
            default => self::STATUS_REVIEW,
        };
    }

    /**
     * @return Collection<int, Enrollment>
     */
    public function loadEnrollments(User $student): Collection
    {
        return Enrollment::query()
            ->where('student_id', $student->id)
            ->where('status', EnrollmentStatus::Active->value)
            ->with([
                'course' => fn ($query) => $query->with([
                    'sections' => fn ($query) => $query->orderBy('sort_order')->with([
                        'lessons' => fn ($query) => $query->published()->orderBy('sort_order')->with([
                            'quiz:id,course_id,title',
                            'progress' => fn ($query) => $query->where('student_id', $student->id),
                        ]),
                    ]),
                ]),
            ])
            ->orderByDesc('last_accessed_at')
            ->get();
    }

    /**
     * @return Collection<int, Collection<int, QuizAttempt>>
     */
    public function loadQuizAttempts(User $student): Collection
    {
        return QuizAttempt::query()
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->get(['id', 'quiz_id', 'score_percentage', 'submitted_at'])
            ->groupBy('quiz_id');
    }

    protected function isCompletedByStudent(Lesson $lesson): bool
    {
        return $lesson->progress->first()?->isCompleted() ?? false;
    }
}
