<?php

namespace App\Http\Resources;

use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'time_limit_minutes' => $this->time_limit_minutes,
            'passing_score' => (float) $this->passing_score,
            'attempts_allowed' => $this->attempts_allowed,
            'status' => $this->status,
            // The availability window rides along so the overview can explain
            // itself ("opens ...", "closed ...") without the student clicking the
            // button and receiving a rejection.
            'available_from' => $this->available_from?->toISOString(),
            'available_until' => $this->available_until?->toISOString(),
            'availability' => $this->availabilityAt(now()),
            // What the student will actually be served, which for a bank quiz is
            // the draw size rather than the (empty) list of attached questions.
            'questions_count' => $this->plannedQuestionCount(),
            'attached_questions_count' => $this->when(isset($this->questions_count), fn () => (int) $this->questions_count),
            'draw_size' => $this->draw_size,
            'question_bank_id' => $this->question_bank_id,
            'total_points' => $this->whenLoaded('questions', fn () => $this->questions->sum('points')),
            'lesson' => $this->whenLoaded('lesson', fn () => [
                'id' => $this->lesson->id,
                'title' => $this->lesson->title,
                'course_slug' => $this->course->slug ?? null,
            ]),
            'attempts_used' => $user ? $this->attemptsFor($user) : 0,
            // A half-finished paper, so the overview can offer to resume it
            // rather than to start over. An attempt past its deadline is not
            // offered: the student's work on it is already lost, and calling it
            // resumable would promise something clicking it cannot deliver.
            'live_attempt' => $this->when(
                $user !== null && ($live = $this->resumableAttemptFor($user)) !== null,
                fn (): array => [
                    'id' => $live->id,
                    'started_at' => $live->started_at?->toISOString(),
                    'expires_at' => $this->deadlineFor($live)?->toISOString(),
                ],
            ),
            'best_score' => $user ? ($this->bestAttemptFor($user)?->score_percentage) : null,
            'has_passed' => $user ? $this->attempts()->where('student_id', $user->id)->where('passed', true)->exists() : null,
            // The student's own recent attempts, so the overview can point at a
            // review of each graded one. Only rows the student owns are ever
            // loaded in `show()`, and a row is reviewable exactly when it has
            // been graded -- which for an expired attempt is true even though
            // its status says something other than `completed`.
            'attempts' => $this->whenLoaded('attempts', fn (): array => $this->attempts
                ->map(fn (QuizAttempt $attempt): array => [
                    'id' => $attempt->id,
                    'status' => $attempt->status->value,
                    'started_at' => $attempt->started_at?->toISOString(),
                    'submitted_at' => $attempt->submitted_at?->toISOString(),
                    'score' => $attempt->score !== null ? (float) $attempt->score : null,
                    'score_percentage' => $attempt->score_percentage !== null ? (float) $attempt->score_percentage : null,
                    'passed' => $attempt->passed,
                    'reviewable' => $attempt->isGraded(),
                ])
                ->all()),
        ];
    }
}
