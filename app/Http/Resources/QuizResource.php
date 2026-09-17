<?php

namespace App\Http\Resources;

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
            'questions_count' => $this->questions_count,
            'total_points' => $this->whenLoaded('questions', fn () => $this->questions->sum('points')),
            'lesson' => $this->whenLoaded('lesson', fn () => [
                'id' => $this->lesson->id,
                'title' => $this->lesson->title,
                'course_slug' => $this->course->slug ?? null,
            ]),
            'attempts_used' => $user ? $this->attemptsFor($user) : 0,
            'best_score' => $user ? ($this->bestAttemptFor($user)?->score_percentage) : null,
            'has_passed' => $user ? $this->attempts()->where('student_id', $user->id)->where('passed', true)->exists() : null,
        ];
    }
}
