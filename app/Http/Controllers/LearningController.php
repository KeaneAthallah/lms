<?php

namespace App\Http\Controllers;

use App\Http\Resources\CourseResource;
use App\Http\Resources\LessonResource;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\ProgressService;

class LearningController extends Controller
{
    public function __construct(protected ProgressService $progress) {}

    /**
     * Return the full curriculum for an enrolled student or course owner.
     */
    public function show(Course $course)
    {
        $user = request()->user();
        $this->authorize('learn', $course);

        $course->loadMissing(['instructor.roles', 'category', 'enrollments' => fn ($q) => $q->where('student_id', $user->id)])
            ->loadCount(['lessons']);

        $course->load([
            'sections' => fn ($q) => $q->with([
                'lessons' => function ($q) use ($user) {
                    $q->published()
                        ->orderBy('sort_order')
                        ->with([
                            'materials',
                            'quiz' => fn ($q) => $q->withCount('questions')
                                ->with(['attempts' => fn ($a) => $a->where('student_id', $user->id)]),
                            'assignment.submissions' => fn ($q) => $q->where('student_id', $user->id),
                            'progress' => fn ($q) => $q->where('student_id', $user->id),
                        ]);
                },
            ]),
        ]);

        $enrollment = $course->enrollments->first();

        return response()->json([
            'course' => (new CourseResource($course))->resolve(),
            'enrollment' => $enrollment ? [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'progress_percent' => $enrollment->progress_percent,
                'completed_at' => $enrollment->completed_at?->toISOString(),
            ] : null,
        ]);
    }

    /**
     * Return a single lesson with its content, materials and navigation.
     */
    public function showLesson(Course $course, Lesson $lesson)
    {
        $user = request()->user();
        $this->authorize('learn', $course);

        abort_unless((int) $lesson->course_id === (int) $course->id, 404);

        $lesson->load(['materials', 'quiz' => fn ($q) => $q->withCount('questions'), 'assignment.submissions' => fn ($q) => $q->where('student_id', $user->id)])
            ->load(['progress' => fn ($q) => $q->where('student_id', $user->id)]);

        $this->progress->markStarted($lesson, $user);

        $lessons = $course->lessons()->published()->orderBy('sort_order')->get();

        $currentIndex = $lessons->search(fn (Lesson $item): bool => $item->id === $lesson->id);

        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'lesson' => (new LessonResource($lesson))->resolve(),
            'navigation' => [
                'previous' => $currentIndex !== false && $currentIndex > 0 ? $lessons[$currentIndex - 1]->id : null,
                'next' => $currentIndex !== false && $currentIndex < $lessons->count() - 1 ? $lessons[$currentIndex + 1]->id : null,
                'total' => $lessons->count(),
                'current_index' => $currentIndex === false ? null : $currentIndex + 1,
            ],
            'progress' => $course->enrollments()
                ->where('student_id', $user->id)
                ->first(['progress_percent'])?->progress_percent ?? 0,
        ]);
    }
}
