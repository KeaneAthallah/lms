<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InstructorMaterialController extends Controller
{
    public function store(Request $request, Course $course, Lesson $lesson)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $lesson->course_id === (int) $course->id, 404);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'is_downloadable' => ['boolean'],
        ]);

        $file = $request->file('file');
        $path = $file->store('lessons/materials/'.$lesson->id, 'local');

        $material = $lesson->materials()->create([
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'type' => $file->extension(),
            'is_downloadable' => $data['is_downloadable'] ?? true,
            'sort_order' => (int) $lesson->materials()->max('sort_order') + 1,
        ]);

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
