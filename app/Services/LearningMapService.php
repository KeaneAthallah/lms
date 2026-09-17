<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Aggregates the student's concept mastery map per active course, together with
 * the next lesson and the state of the next upcoming quiz. Deterministic rules
 * only - every value traces back to the student's own activity.
 */
class LearningMapService
{
    /**
     * @return array<string, mixed>
     */
    public function mapFor(User $student): array
    {
        $calculator = new MasteryCalculator;
        $insights = new LearningInsightService;

        $enrollments = $calculator->loadEnrollments($student);
        $attemptsByQuiz = $calculator->loadQuizAttempts($student);
        $mastery = $calculator->build($enrollments, $attemptsByQuiz);

        $courses = [];

        foreach ($enrollments as $enrollment) {
            $course = $enrollment->course;

            $lessons = collect();

            foreach ($course->sections as $section) {
                foreach ($section->lessons as $lesson) {
                    $lessons->push($lesson);
                }
            }

            $total = $lessons->count();
            $completed = $lessons->filter(fn (Lesson $lesson) => $lesson->progress->first()?->isCompleted() ?? false)->count();
            $progressPercent = $total === 0 ? 0 : (int) round($completed / $total * 100);

            $nextLesson = $lessons->first(fn (Lesson $lesson) => ! ($lesson->progress->first()?->isCompleted() ?? false));

            $courseMastery = collect($mastery['courses'])
                ->first(fn (array $entry) => (int) $entry['course']['id'] === (int) $course->id);

            $upcomingQuiz = $this->nextUpcomingQuiz($lessons, $attemptsByQuiz, $student, $insights);

            $courses[] = [
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                ],
                'progress_percent' => $progressPercent,
                'completed_lessons' => $completed,
                'total_lessons' => $total,
                'concepts' => $courseMastery['concepts'] ?? [],
                'next_lesson' => $nextLesson === null ? null : [
                    'id' => $nextLesson->id,
                    'title' => $nextLesson->title,
                    'type' => $nextLesson->type?->value ?? 'unknown',
                    'to' => "/learn/{$course->slug}/{$nextLesson->id}",
                ],
                'upcoming_quiz' => $upcomingQuiz,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'method' => 'deterministic_rules',
            'overall_percent' => $mastery['overall_percent'],
            'courses' => $courses,
        ];
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     * @param  Collection<int, Collection<int, QuizAttempt>>  $attemptsByQuiz
     * @return array<string, mixed>|null
     */
    private function nextUpcomingQuiz($lessons, $attemptsByQuiz, User $student, LearningInsightService $insights): ?array
    {
        foreach ($lessons as $lesson) {
            if ($lesson->quiz_id === null || $lesson->quiz === null) {
                continue;
            }

            if (($attemptsByQuiz->get($lesson->quiz_id) ?? collect())
                ->contains(fn (QuizAttempt $attempt) => (bool) $attempt->passed)) {
                continue;
            }

            $readiness = $insights->quizReadinessFor($student, $lesson->quiz);

            if ($readiness !== null) {
                return $readiness;
            }
        }

        return null;
    }
}
