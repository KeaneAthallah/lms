<?php

namespace App\Http\Controllers;

use App\Http\Requests\Lesson\StoreLessonRequest;
use App\Http\Requests\Lesson\UpdateLessonRequest;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InstructorLessonController extends Controller
{
    /**
     * Lesson videos live on private storage and are served through
     * `lessons.video`, which authorizes the request. The `public` disk must not
     * be used here: `public/storage` symlinks to it, so anything written there
     * is world-readable.
     */
    private const VIDEO_DISK = 'local';

    public function store(StoreLessonRequest $request, Course $course, CourseSection $section)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $section->course_id === (int) $course->id, 404);

        $data = $this->normalizePayload($request, $course);

        $data['slug'] = str($request->input('title'))->slug().'-'.str()->lower(str()->random(6));
        $data['sort_order'] = (int) $section->lessons()->max('sort_order') + 1;
        $data['course_id'] = $course->id;
        $data['is_published'] = (bool) ($data['is_published'] ?? true);

        $lesson = $section->lessons()->create($data);

        return response()->json([
            'message' => 'Lesson created.',
            'lesson' => $this->summary($lesson),
        ], 201);
    }

    public function update(UpdateLessonRequest $request, Course $course, Lesson $lesson)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $lesson->course_id === (int) $course->id, 404);

        $data = $this->normalizePayload($request, $course, $lesson);

        if ($request->has('is_published')) {
            $data['is_published'] = $request->boolean('is_published');
        }

        $lesson->update($data);

        return response()->json([
            'message' => 'Lesson updated.',
            'lesson' => [
                ...$this->summary($lesson),
                'content' => $lesson->content,
            ],
        ]);
    }

    public function destroy(Request $request, Course $course, Lesson $lesson)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $lesson->course_id === (int) $course->id, 404);

        if ($lesson->video_path) {
            Storage::disk($lesson->video_disk ?: self::VIDEO_DISK)->delete($lesson->video_path);
        }

        $lesson->materials()->get()->each(function ($material): void {
            if ($material->path) {
                Storage::disk($material->disk ?? 'local')->delete($material->path);
            }
            $material->delete();
        });

        if ($lesson->quiz) {
            $lesson->quiz->questions()->delete();
            $lesson->quiz->delete();
        }

        $lesson->delete();

        return response()->json(['message' => 'Lesson deleted.']);
    }

    /**
     * Validate-then-sanitise the video and body fields.
     *
     * `content` is rendered with `dangerouslySetInnerHTML`, so it is sanitised
     * on write: the stored value must be safe to hand straight to a browser.
     */
    private function normalizePayload(StoreLessonRequest|UpdateLessonRequest $request, Course $course, ?Lesson $lesson = null): array
    {
        $data = $request->safe()->except(['video']);

        $isVideo = $request->string('type')->toString() === 'video';
        $hasFile = $request->hasFile('video');
        $hasUrl = filled($request->input('video_url'));

        if ($isVideo) {
            $removing = $request->boolean('remove_video');

            if ($removing) {
                $this->deleteStoredVideo($lesson);
                $data['video_path'] = null;
                $data['video_disk'] = null;
                $data['video_url'] = null;
            } elseif ($hasFile || $hasUrl) {
                abort_if($hasFile === $hasUrl, 422, 'Provide exactly one of: a video file or an external video URL.');

                if ($hasFile) {
                    $this->deleteStoredVideo($lesson);

                    $data['video_path'] = $request->file('video')
                        ->store('lessons/videos/'.$course->getKey(), self::VIDEO_DISK);
                    $data['video_disk'] = self::VIDEO_DISK;
                    $data['video_url'] = null;
                } else {
                    $data['video_url'] = $request->input('video_url');
                    $data['video_path'] = null;
                    $data['video_disk'] = null;
                }
            }
        } else {
            $this->deleteStoredVideo($lesson);
            $data['video_path'] = null;
            $data['video_disk'] = null;
            $data['video_url'] = null;
        }

        if (array_key_exists('content', $data)) {
            $data['content'] = HtmlSanitizer::clean($data['content']);
        }

        return $data;
    }

    private function deleteStoredVideo(?Lesson $lesson): void
    {
        if ($lesson?->video_path) {
            Storage::disk($lesson->video_disk ?: self::VIDEO_DISK)->delete($lesson->video_path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'slug' => $lesson->slug,
            'type' => $lesson->type->value,
            'is_published' => $lesson->is_published,
            'video_url' => $lesson->video_path
                ? route('lessons.video', $lesson)
                : $lesson->video_url,
            'external_url' => $lesson->external_url,
            'duration_seconds' => $lesson->duration_seconds,
            'sort_order' => $lesson->sort_order,
        ];
    }
}
