<?php

namespace App\Http\Controllers;

use App\CategoryStatus;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\CourseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = CourseCategory::withCount(['courses'])->with(['courses:id,title,slug']);

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        $categories = $query->orderBy('name')->paginate(15)->withQueryString();

        return response()->json([
            'categories' => $categories->map(fn (CourseCategory $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'icon' => $c->icon,
                'is_active' => $c->status === CategoryStatus::Active,
                'courses_count' => $c->courses_count,
                'updated_at' => $c->updated_at?->toISOString(),
            ]),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'total' => $categories->total(),
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request)
    {
        $category = CourseCategory::create([
            ...$request->safe()->only(['name', 'description', 'icon']),
            'slug' => $request->filled('slug')
                ? Str::slug($request->input('slug'))
                : Str::slug($request->input('name')),
            'status' => $request->boolean('is_active', true) ? 'active' : 'inactive',
        ]);

        return response()->json([
            'message' => 'Category created.',
            'category' => $this->categoryPayload($category),
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, CourseCategory $category)
    {
        $category->update([
            ...$request->safe()->only(['name', 'description', 'icon']),
            'slug' => $request->filled('slug') ? Str::slug($request->input('slug')) : Str::slug($request->input('name', $category->name)),
            'status' => $request->boolean('is_active', $category->status === CategoryStatus::Active) ? 'active' : 'inactive',
        ]);

        return response()->json([
            'message' => 'Category updated.',
            'category' => $this->categoryPayload($category),
        ]);
    }

    public function destroy(Request $request, CourseCategory $category)
    {
        if ($category->courses()->exists()) {
            return response()->json(['message' => 'Category has courses and cannot be deleted.'], 409);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    private function categoryPayload(CourseCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'icon' => $category->icon,
            'is_active' => $category->status === CategoryStatus::Active,
            'courses_count' => $category->courses()->count(),
        ];
    }
}
