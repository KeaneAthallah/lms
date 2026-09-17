<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'thumbnail_url' => $this->thumbnail_path ? asset('storage/'.$this->thumbnail_path) : null,
            'short_description' => $this->short_description,
            'level' => $this->level,
            'language' => $this->language,
            'duration_minutes' => $this->duration_minutes,
            'price' => $this->price !== null ? (float) $this->price : null,
            'instructor' => [
                'id' => $this->instructor->id ?? null,
                'name' => $this->instructor->name ?? 'Unknown',
                'headline' => $this->whenNotNull($this->instructor->headline ?? null),
            ],
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'lessons_count' => $this->whenCounted('lessons'),
            'enrollments_count' => $this->whenCounted('enrollments'),
        ];
    }
}
