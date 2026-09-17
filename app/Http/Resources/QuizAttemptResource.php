<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'score' => $this->score !== null ? (float) $this->score : null,
            'score_percentage' => $this->score_percentage !== null ? (float) $this->score_percentage : null,
            'passed' => $this->passed,
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'quiz' => $this->whenLoaded('quiz', fn () => [
                'id' => $this->quiz->id,
                'title' => $this->quiz->title,
                'passing_score' => (float) $this->quiz->passing_score,
                'total_points' => $this->quiz->questions->sum('points'),
            ]),
            'answers' => $this->whenLoaded('answers', fn () => $this->answers->map(fn ($answer) => [
                'question_id' => $answer->quiz_question_id,
                'answer' => $answer->answer,
                'is_correct' => $answer->is_correct,
                'points_earned' => (float) $answer->points_earned,
            ])),
        ];
    }
}
