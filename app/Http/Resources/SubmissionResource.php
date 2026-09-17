<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'content' => $this->content,
            'files' => collect($this->files ?? [])->map(function ($file, $index) {
                return [
                    'index' => $index,
                    'name' => $file['name'] ?? $file['filename'],
                    'size' => $file['size'] ?? null,
                    'type' => $file['type'] ?? null,
                ];
            })->values(),
            'version' => $this->version,
            'status' => $this->status->value,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'grade' => $this->grade !== null ? (float) $this->grade : null,
            'feedback' => $this->feedback,
            'graded_at' => $this->graded_at?->toISOString(),
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'name' => $this->student->name,
                'email' => $this->student->email,
            ]),
            'assignment' => $this->whenLoaded('assignment', fn () => [
                'id' => $this->assignment->id,
                'title' => $this->assignment->title,
                'max_score' => $this->assignment->max_score,
            ]),
            'grader' => $this->whenLoaded('grader', fn () => $this->grader?->name),
        ];
    }
}
