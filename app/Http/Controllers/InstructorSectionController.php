<?php

namespace App\Http\Controllers;

use App\Http\Requests\CourseSection\StoreCourseSectionRequest;
use App\Http\Requests\CourseSection\UpdateCourseSectionRequest;
use App\Models\Course;
use App\Models\CourseSection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InstructorSectionController extends Controller
{
    public function store(StoreCourseSectionRequest $request, Course $course)
    {
        $this->authorize('manage', $course);

        $section = $course->sections()->create([
            ...$request->safe()->only(['title', 'description']),
            'sort_order' => (int) $course->sections()->max('sort_order') + 1,
        ]);

        return response()->json([
            'message' => 'Section created.',
            'section' => [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'sort_order' => $section->sort_order,
                'lessons_count' => 0,
            ],
        ], 201);
    }

    public function update(UpdateCourseSectionRequest $request, Course $course, CourseSection $section)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $section->course_id === (int) $course->id, 404);

        $section->update($request->validated());

        return response()->json([
            'message' => 'Section updated.',
            'section' => [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'sort_order' => $section->sort_order,
                'lessons_count' => $section->lessons()->count(),
            ],
        ]);
    }

    public function reorder(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        $data = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*' => ['required', 'integer', 'distinct'],
        ]);

        foreach ($data['sections'] as $index => $sectionId) {
            $section = $course->sections()->find($sectionId);
            if ($section) {
                $section->update(['sort_order' => $index + 1]);
            }
        }

        return response()->json(['message' => 'Section order updated.']);
    }

    public function destroy(Request $request, Course $course, CourseSection $section)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $section->course_id === (int) $course->id, 404);

        $lessons = $section->lessons()->get();
        foreach ($lessons as $lesson) {
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
            if ($lesson->assignment) {
                $lesson->assignment->delete();
            }
            $lesson->delete();
        }

        $section->delete();

        return response()->json(['message' => 'Section deleted.']);
    }
}
