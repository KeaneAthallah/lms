<?php

namespace App\Http\Controllers;

use App\Http\Requests\Lesson\UploadLessonMaterialRequest;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InstructorMaterialController extends Controller
{
    public function store(UploadLessonMaterialRequest $request, Course $course, Lesson $lesson)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $lesson->course_id === (int) $course->id, 404);

        $data = $request->safe()->only('is_downloadable');

        $file = $request->file('file');
        $path = $file->store('lessons/materials/'.$lesson->id, 'local');

        $material = DB::transaction(function () use ($lesson, $file, $path, $data) {
            // `max('sort_order') + 1` is a read-then-write, so the lesson row is
            // locked for the duration of both halves. Without the lock two
            // uploads landing together each read the same maximum and each insert
            // it, leaving two materials with an identical `sort_order` -- which
            // the listing breaks arbitrarily, since the index on
            // `(lesson_id, sort_order)` is not unique and nothing raises.
            Lesson::whereKey($lesson->id)->lockForUpdate()->first();

            return $lesson->materials()->create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'disk' => 'local',
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'type' => $file->extension(),
                'is_downloadable' => $data['is_downloadable'] ?? true,
                'sort_order' => (int) $lesson->materials()->max('sort_order') + 1,
            ]);
        });

        return response()->json([
            'message' => 'Material uploaded.',
            'material' => [
                'id' => $material->id,
                'filename' => $material->filename,
                'type' => $material->type,
                'size' => $material->size,
                'is_downloadable' => $material->is_downloadable,
            ],
        ], 201);
    }

    public function destroy(Request $request, Course $course, Lesson $lesson, LessonMaterial $material)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $material->lesson_id === (int) $lesson->id, 404);

        if ($material->path) {
            Storage::disk($material->disk ?? 'local')->delete($material->path);
        }

        $material->delete();

        return response()->json(['message' => 'Material removed.']);
    }
}
