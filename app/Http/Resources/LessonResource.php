<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $studentId = $request->user()?->id;
        $progress = $this->relationLoaded('progress')
            ? $this->progress->firstWhere('student_id', $studentId)
            : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type->value,
            'content' => $this->content,
            'video_url' => $this->video_path
                ? asset('storage/'.$this->video_path)
                : ($this->video_url ?? null),
            'external_url' => $this->external_url,
            'duration_seconds' => $this->duration_seconds,
            'is_published' => $this->is_published,
            'sort_order' => $this->sort_order,
            'materials' => $this->whenLoaded('materials', fn () => $this->materials->map(fn ($m) => [
                'id' => $m->id,
                'filename' => $m->filename,
                'size' => $m->size,
                'mime_type' => $m->mime_type,
                'type' => $m->type,
                'is_downloadable' => $m->is_downloadable,
                'last_modified' => $m->updated_at?->toISOString(),
            ])),
            'quiz' => $this->whenLoaded('quiz', fn () => [
                'id' => $this->quiz->id,
                'title' => $this->quiz->title,
                'description' => $this->quiz->description,
                'instructions' => $this->quiz->instructions,
                'passing_score' => (float) $this->quiz->passing_score,
                'attempts_allowed' => $this->quiz->attempts_allowed,
                'status' => $this->quiz->status,
                'questions_count' => $this->quiz->questions_count ?? 0,
                'has_passed' => $this->whenLoaded('quiz.attempts', fn () => $this->quiz->attempts->where('passed', true)->isNotEmpty()),
            ]),
            'assignment' => $this->whenLoaded('assignment', fn () => [
                'id' => $this->assignment->id,
                'title' => $this->assignment->title,
                'description' => $this->assignment->description,
                'instructions' => $this->assignment->instructions,
                'due_at' => $this->assignment->due_at?->toISOString(),
                'max_score' => (float) $this->assignment->max_score,
                'status' => $this->assignment->status->value,
                'submission' => $this->whenLoaded('assignment.submissions', function () use ($studentId) {
                    $sub = $this->relationLoaded('assignment') && $this->assignment->relationLoaded('submissions')
                        ? $this->assignment->submissions->firstWhere('student_id', $studentId)
                        : null;

                    return $sub ? SubmissionResource::make($sub) : null;
                }),
            ]),
            'progress' => $progress ? [
                'percent' => $progress->progress_percent,
                'video_position' => $progress->video_position_seconds,
                'completed' => $progress->isCompleted(),
            ] : null,
        ];
    }
}
