<?php

namespace App\Http\Controllers;

use App\Http\Requests\Assignment\StoreAssignmentRequest;
use App\Http\Requests\Assignment\UpdateAssignmentRequest;
use App\Models\Assignment;
use App\Models\Course;
use Illuminate\Http\Request;

class InstructorAssignmentController extends Controller
{
    public function store(StoreAssignmentRequest $request, Course $course)
    {
        $this->authorize('manage', $course);

        $lessonTitle = $request->input('title');

        $section = $course->sections()->find((int) $request->integer('section_id')) ?? null;

        $lesson = $course->lessons()->create([
            'section_id' => $section?->id ?? $course->sections()->first()?->id,
            'title' => $lessonTitle,
            'type' => 'assignment',
            'course_id' => $course->id,
            'is_published' => true,
            'sort_order' => (int) $course->lessons()->max('sort_order') + 1,
        ]);

        $assignment = $course->assignments()->create([
            ...$request->safe()->except(['due_at']),
            'due_at' => $request->filled('due_at') ? $request->input('due_at') : null,
        ]);

        $lesson->update(['assignment_id' => $assignment->id]);

        return response()->json([
            'message' => 'Assignment attached to the course.',
            'assignment' => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'instructions' => $assignment->instructions,
                'max_score' => (float) $assignment->max_score,
                'due_at' => $assignment->due_at?->toISOString(),
                'allowed_file_types' => $assignment->allowed_file_types,
                'max_file_size_kb' => $assignment->max_file_size_kb,
                'status' => $assignment->status->value,
                'lesson_id' => $lesson->id,
            ],
        ], 201);
    }

    public function update(UpdateAssignmentRequest $request, Course $course, Assignment $assignment)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $assignment->course_id === (int) $course->id, 404);

        $assignment->update([
            ...$request->safe()->except(['due_at']),
            'due_at' => $request->filled('due_at') ? $request->input('due_at') : null,
        ]);

        return response()->json([
            'message' => 'Assignment updated.',
            'assignment' => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'instructions' => $assignment->instructions,
                'max_score' => (float) $assignment->max_score,
                'due_at' => $assignment->due_at?->toISOString(),
                'status' => $assignment->status->value,
            ],
        ]);
    }

    public function destroy(Request $request, Course $course, Assignment $assignment)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $assignment->course_id === (int) $course->id, 404);

        if ($assignment->lesson) {
            $assignment->lesson->update(['type' => 'text', 'assignment_id' => null]);
        }

        $assignment->delete();

        return response()->json(['message' => 'Assignment removed.']);
    }
}
