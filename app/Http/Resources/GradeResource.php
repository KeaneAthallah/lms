<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'score' => (float) $this->score,
            'max_score' => (float) $this->max_score,
            'percentage' => (float) $this->percentage,
            'feedback' => $this->feedback,
            'graded_at' => $this->graded_at?->toISOString(),
            'course' => $this->whenLoaded('course', fn () => [
                'id' => $this->course->id,
                'title' => $this->course->title,
                'slug' => $this->course->slug,
            ]),
        ];
    }
}
