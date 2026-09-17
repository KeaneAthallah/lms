<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'course' => $this->whenLoaded('course', fn () => [
                'id' => $this->course->id,
                'title' => $this->course->title,
                'slug' => $this->course->slug,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'due_at' => $this->due_at?->toISOString(),
            'max_score' => (float) $this->max_score,
            'allowed_file_types' => $this->allowed_file_types ?? [],
            'max_file_size_kb' => $this->max_file_size_kb,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toISOString(),
            'my_submission' => $user && $this->relationLoaded('submissions')
                && ($submission = $this->submissions->firstWhere('student_id', $user->id))
                ? SubmissionResource::make($submission)->resolve()
                : null,
            'submission_count' => $this->whenCounted('submissions'),
        ];
    }
}
