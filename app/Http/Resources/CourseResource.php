<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $enrollment = $this->relationLoaded('enrollments')
            ? $this->enrollments->firstWhere('student_id', $user?->id)
            : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnail_path ? asset('storage/'.$this->thumbnail_path) : null,
            'level' => $this->level,
            'language' => $this->language,
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status->value,
            'price' => $this->price !== null ? (float) $this->price : null,
            'learning_objectives' => $this->learning_objectives ?? [],
            'requirements' => $this->requirements ?? [],
            'published_at' => $this->published_at?->toISOString(),
            'instructor' => new UserResource($this->whenLoaded('instructor')),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'lessons_count' => $this->whenCounted('lessons'),
            'enrollments_count' => $this->whenCounted('enrollments'),
            'sections' => $this->whenLoaded('sections', fn () => SectionResource::collection($this->sections)),
            'enrollment' => $enrollment ? [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'progress_percent' => $enrollment->progress_percent,
                'completed_at' => $enrollment->completed_at?->toISOString(),
            ] : null,
            'is_owned' => $user !== null && (int) $this->instructor_id === (int) $user->id,
        ];
    }
}
