<?php

namespace App\Http\Resources;

use App\Support\CourseAccess;
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

        // Curriculum structure is public; the lesson body and its media are not.
        // Both keys are always present so the response contract is unchanged.
        $canViewContent = $this->canViewContent($request);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type->value,
            'content' => $canViewContent ? $this->content : null,
            'video_url' => $canViewContent ? $this->videoUrl() : null,
            'external_url' => $canViewContent ? $this->external_url : null,
            'duration_seconds' => $this->duration_seconds,
            'is_published' => $this->is_published,
            'sort_order' => $this->sort_order,
            'materials' => $canViewContent
                ? $this->whenLoaded('materials', fn () => $this->materials->map(fn ($m) => [
                    'id' => $m->id,
                    'filename' => $m->filename,
                    'size' => $m->size,
                    'mime_type' => $m->mime_type,
                    'type' => $m->type,
                    'is_downloadable' => $m->is_downloadable,
                    'last_modified' => $m->updated_at?->toISOString(),
                ]))
                : [],
            'quiz' => $this->whenLoaded('quiz', fn () => [
                'id' => $this->quiz->id,
                'title' => $this->quiz->title,
                'description' => $this->quiz->description,
                'instructions' => $this->quiz->instructions,
                'passing_score' => (float) $this->quiz->passing_score,
                'attempts_allowed' => $this->quiz->attempts_allowed,
                'status' => $this->quiz->status,
                // A bank quiz draws its questions at attempt time, so the count
                // is the draw size rather than an attached question total.
                'question_bank_id' => $this->quiz->question_bank_id,
                'draw_size' => $this->quiz->draw_size,
                'questions_count' => $this->quiz->plannedQuestionCount(),
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

    private function canViewContent(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return app(CourseAccess::class)->canViewLessonContent($user, $this->resource);
    }

    /**
     * Stored videos are served by an authorization-controlled route rather than
     * a public `asset()` URL; external providers (YouTube, Vimeo) are returned
     * as-is.
     */
    private function videoUrl(): ?string
    {
        if ($this->video_path) {
            return route('lessons.video', $this->resource);
        }

        return $this->video_url ?: null;
    }
}
