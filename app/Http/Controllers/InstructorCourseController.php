<?php

namespace App\Http\Controllers;

use App\Http\Requests\Course\StoreCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Http\Resources\CourseCardResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstructorCourseController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->isAdmin()) {
            $courses = Course::with(['instructor:id,name', 'category:id,name'])
                ->withCount(['lessons', 'enrollments']);
        } else {
            $courses = $request->user()->courses()
                ->with(['instructor:id,name', 'category:id,name'])
                ->withCount(['lessons', 'enrollments']);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $courses->where('title', 'like', "%{$search}%");
        }

        if ($request->has('status') && in_array($request->string('status')->toString(), ['draft', 'published', 'archived'], true)) {
            $courses->where('status', $request->string('status')->toString());
        }

        $courses = $courses->orderByDesc('updated_at')->paginate(12)->withQueryString();

        return CourseCardResource::collection($courses);
    }

    public function store(StoreCourseRequest $request)
    {
        $data = $request->safe()->except('thumbnail');
        $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(6));

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail_path'] = $request->file('thumbnail')->store('thumbnails', 'public');
        }

        $course = $request->user()->courses()->create($data);

        $course->load(['instructor:id,name', 'category:id,name'])->loadCount(['lessons', 'enrollments']);

        return (new CourseResource($course))
            ->additional(['message' => 'Course created. Add sections and lessons to get started.']);
    }

    public function show(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        $course->loadCount(['lessons', 'enrollments']);

        $course->load([
            'instructor:id,name,avatar_path',
            'category:id,name,slug',
            'sections' => fn ($q) => $q->with([
                'lessons' => fn ($q) => $q->orderBy('sort_order')->with([
                    'materials',
                    'quiz.questions.options',
                    'assignment',
                ]),
            ]),
        ]);

        return response()->json([
            'course' => (new CourseResource($course))->resolve(),
            'total_lessons' => $course->lessons_count,
            'total_enrollments' => $course->enrollments_count,
        ]);
    }

    public function update(UpdateCourseRequest $request, Course $course)
    {
        $this->authorize('manage', $course);

        $data = $request->safe()->except(['thumbnail', 'status']);

        if ($request->hasFile('thumbnail')) {
            if ($course->thumbnail_path) {
                Storage::disk('public')->delete($course->thumbnail_path);
            }
            $data['thumbnail_path'] = $request->file('thumbnail')->store('thumbnails', 'public');
        }

        $course->update($data);

        $course->loadMissing(['category:id,name,slug'])->loadCount(['lessons', 'enrollments']);

        return (new CourseResource($course))->additional(['message' => 'Course updated.']);
    }

    public function status(Request $request, Course $course, string $status)
    {
        $this->authorize('manage', $course);

        abort_unless(in_array($status, ['draft', 'published', 'archived'], true), 422);

        $data = ['status' => $status];
        if ($status === 'published' && ! $course->published_at) {
            $data['published_at'] = now();
        }

        $course->update($data);

        return response()->json([
            'message' => 'Course is now '.$status.'.',
            'status' => $status,
        ]);
    }

    public function destroy(Request $request, Course $course)
    {
        $this->authorize('delete', $course);

        if ($course->enrollments()->exists()) {
            return response()->json(['message' => 'This course has enrollments and cannot be deleted. Archive it instead.'], 409);
        }

        if ($course->thumbnail_path) {
            Storage::disk('public')->delete($course->thumbnail_path);
        }

        $course->delete();

        return response()->json(['message' => 'Course deleted.']);
    }
}
