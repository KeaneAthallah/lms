<?php

namespace App\Http\Controllers;

use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\Request;

class AdminCourseController extends Controller
{
    public function index(Request $request)
    {
        $query = Course::with(['instructor:id,name', 'category:id,name'])
            ->withCount(['lessons', 'enrollments']);

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($request->has('status') && in_array($request->string('status')->toString(), ['draft', 'published', 'archived'], true)) {
            $query->where('status', $request->string('status')->toString());
        }

        $courses = $query->latest()->paginate(15)->withQueryString();

        return CourseResource::collection($courses);
    }

    public function show(Request $request, Course $course)
    {
        $course->load(['instructor:id,name,email', 'category:id,name,slug'])
            ->loadCount(['lessons', 'enrollments'])
            ->load(['sections.lessons' => fn ($q) => $q->with(['quiz', 'assignment'])->orderBy('sort_order')]);

        return new CourseResource($course);
    }

    public function destroy(Request $request, Course $course)
    {
        if ($course->enrollments()->exists()) {
            return response()->json(['message' => 'This course has enrollments and cannot be deleted. Archive it instead.'], 409);
        }

        $course->delete();

        return response()->json(['message' => 'Course deleted.']);
    }
}
