<?php

namespace App\Http\Controllers;

use App\Http\Requests\Lesson\StoreLessonRequest;
use App\Http\Requests\Lesson\UpdateLessonRequest;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InstructorLessonController extends Controller
{
    public function store(StoreLessonRequest $request, Course $course, CourseSection $section)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $section->course_id === (int) $course->id, 404);

        $data = $request->safe()->except(['video']);

        if ($request->string('type')->toString() === 'video') {
            $hasFile = $request->hasFile('video');
            $hasUrl = filled($request->input('video_url'));

            abort_if($hasFile === $hasUrl, 422, 'Provide exactly one of: a video file or an external video URL.');

            if ($hasFile) {
                $data['video_path'] = $request->file('video')->store('lessons/videos/'.$course->id, 'public');
                $data['video_url'] = null;
            } else {
                $data['video_url'] = $request->input('video_url');
                $data['video_path'] = null;
            }
        } else {
            $data['video_path'] = null;
            $data['video_url'] = null;
        }

        $data['slug'] = str($request->input('title'))->slug().'-'.str()->lower(str()->random(6));
        $data['sort_order'] = (int) $section->lessons()->max('sort_order') + 1;
        $data['course_id'] = $course->id;
        $data['is_published'] = (bool) ($data['is_published'] ?? true);

        $lesson = $section->lessons()->create($data);

        return response()->json([
            'message' => 'Lesson created.',
            'lesson' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'slug' => $lesson->slug,
                'type' => $lesson->type->value,
                'is_published' => $lesson->is_published,
                'video_url' => $lesson->video_path ? asset('storage/'.$lesson->video_path) : $lesson->video_url,
                'external_url' => $lesson->external_url,
                'duration_seconds' => $lesson->duration_seconds,
                'sort_order' => $lesson->sort_order,
            ],
        ], 201);
    }

    public function update(UpdateLessonRequest $request, Course $course, Lesson $lesson)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $lesson->course_id === (int) $course->id, 404);

        $data = $request->safe()->except(['video', 'remove_video']);

        if ($request->string('type')->toString() === 'video') {
            $hasFile = $request->hasFile('video');
            $hasUrl = filled($request->input('video_url'));

            if ($request->boolean('remove_video')) {
                if ($lesson->video_path) {
                    Storage::disk('public')->delete($lesson->video_path);
                }
                $data['video_path'] = null;
                $data['video_url'] = null;
            } elseif ($hasFile || $hasUrl) {
                abort_if($hasFile === $hasUrl, 422, 'Provide exactly one of: a video file or an external video URL.');

                if ($hasFile) {
                    if ($lesson->video_path) {
                        Storage::disk('public')->delete($lesson->video_path);
                    }
                    $data['video_path'] = $request->file('video')->store('lessons/videos/'.$course->id, 'public');
                    $data['video_url'] = null;
                } else {
                    $data['video_url'] = $request->input('video_url');
                    $data['video_path'] = null;
                }
            }
        } else {
            if ($lesson->video_path) {
                Storage::disk('public')->delete($lesson->video_path);
            }
            $data['video_path'] = null;
            $data['video_url'] = null;
        }

        if ($request->has('is_published')) {
            $data['is_published'] = (bool) $request->boolean('is_published');
        }

        $lesson->update($data);

        return response()->json([
            'message' => 'Lesson updated.',
            'lesson' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'type' => $lesson->type->value,
                'is_published' => $lesson->is_published,
                'video_url' => $lesson->video_path ? asset('storage/'.$lesson->video_path) : $lesson->video_url,
                'external_url' => $lesson->external_url,
                'content' => $lesson->content,
                'duration_seconds' => $lesson->duration_seconds,
                'sort_order' => $lesson->sort_order,
            ],
        ]);
    }

    public function destroy(Request $request, Course $course, Lesson $lesson)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $lesson->course_id === (int) $course->id, 404);

        if ($lesson->video_path) {
            Storage::disk('public')->delete($lesson->video_path);
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
}
