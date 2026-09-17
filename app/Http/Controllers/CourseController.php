<?php

namespace App\Http\Controllers;

use App\Http\Resources\CourseCardResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\CourseCategory;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $query = Course::query()
            ->published()
            ->with(['instructor', 'category'])
            ->withCount(['lessons', 'enrollments']);

        if ($search = $request->string('search')->trim()->toString()) {
            $query->search($search);
        }

        if ($level = $request->string('level')->trim()->toString()) {
            $query->level($level);
        }

        if ($category = $request->string('category')->trim()->toString()) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $category));
        }

        if ($instructor = (int) $request->integer('instructor')) {
            $query->byInstructor($instructor);
        }

        switch ($request->string('sort')->toString()) {
            case 'price_low':
                $query->orderByRaw('price IS NULL')->orderBy('price');
                break;
            case 'price_high':
                $query->orderByRaw('price IS NULL')->orderByDesc('price');
                break;
            case 'title':
                $query->orderBy('title');
                break;
            case 'popular':
                $query->orderByDesc('enrollments_count');
                break;
            default:
                $query->orderByDesc('published_at');
                break;
        }

        $courses = $query->paginate(12)->withQueryString();

        return CourseCardResource::collection($courses);
    }

    public function show(Request $request, Course $course)
    {
        $this->authorize('view', $course);

        $course->loadMissing(['instructor.roles', 'category'])->loadCount(['lessons', 'enrollments']);

        if ($request->user() && ($request->user()->isAdmin() || $course->isOwnedBy($request->user()))) {
            $course->load(['sections.lessons.materials']);
        } else {
            $course->load(['sections' => fn ($q) => $q->with([
                'lessons' => fn ($q) => $q->published()->with('materials'),
            ])]);
        }

        if ($request->user()) {
            $course->load(['enrollments' => fn ($q) => $q->where('student_id', $request->user()->id)]);
        }

        return new CourseResource($course);
    }

    public function categories(Request $request)
    {
        $categories = CourseCategory::active()
            ->withCount(['courses' => fn ($q) => $q->published()])
            ->orderBy('name')
            ->get();

        return $categories->map(fn (CourseCategory $category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'icon' => $category->icon,
            'description' => $category->description,
            'courses_count' => $category->courses_count,
        ]);
    }
}
