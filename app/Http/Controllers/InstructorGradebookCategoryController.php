<?php

namespace App\Http\Controllers;

use App\Http\Requests\Gradebook\AssignAssessmentsRequest;
use App\Http\Requests\Gradebook\StoreGradebookCategoryRequest;
use App\Http\Requests\Gradebook\UpdateGradebookCategoryRequest;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstructorGradebookCategoryController extends Controller
{
    public function index(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        return response()->json([
            'categories' => $course->gradebookCategories->map(fn (GradebookCategory $category): array => $this->payload($category))->values()->all(),
        ]);
    }

    public function store(StoreGradebookCategoryRequest $request, Course $course)
    {
        $this->authorize('manage', $course);

        $category = $course->gradebookCategories()->create([
            'name' => $request->input('name'),
            'weight' => $request->input('weight'),
            'sort_order' => $request->input('sort_order') ?? 0,
        ]);

        return response()->json([
            'message' => 'Category added.',
            'category' => $this->payload($category),
        ], 201);
    }

    public function update(UpdateGradebookCategoryRequest $request, Course $course, GradebookCategory $category)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $category->course_id === (int) $course->id, 404);

        $category->update([
            'name' => $request->input('name'),
            'weight' => $request->input('weight'),
            'sort_order' => $request->input('sort_order') ?? $category->sort_order,
        ]);

        return response()->json([
            'message' => 'Category updated.',
            'category' => $this->payload($category->fresh()),
        ]);
    }

    public function destroy(Request $request, Course $course, GradebookCategory $category)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $category->course_id === (int) $course->id, 404);

        // Refusing the delete keeps the decision visible: letting it through
        // would silently re-home live assessments and move every affected course
        // grade on the strength of a single click.
        if ($category->inUse()) {
            throw ValidationException::withMessages([
                'category' => ['This category still has assessments assigned to it. Move them to another category (or mark them uncategorized) before deleting it.'],
            ]);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    /**
     * Redraw which assessment sits in which bucket in one request.
     */
    public function assign(AssignAssessmentsRequest $request, Course $course)
    {
        $this->authorize('manage', $course);

        DB::transaction(function () use ($request, $course): void {
            foreach ($request->input('selections', []) as $selection) {
                [$type, $id] = $this->splitKey($selection['key']);

                $categoryId = $selection['category_id'] !== '' && $selection['category_id'] !== null
                    ? (int) $selection['category_id']
                    : null;

                $model = $type === 'quiz' ? Quiz::class : Assignment::class;
                $model::query()
                    ->where('course_id', $course->id)
                    ->whereKey($id)
                    ->update(['category_id' => $categoryId]);
            }
        });

        // Return the fresh column list so the client can rebuild the grid
        // without a second round trip.
        $assessments = collect()
            ->concat($course->quizzes()->get(['id', 'title', 'category_id'])->map(fn (Quiz $quiz): array => [
                'key' => "quiz-{$quiz->id}",
                'type' => 'quiz',
                'id' => (int) $quiz->id,
                'title' => $quiz->title,
                'category_id' => $quiz->category_id !== null ? (int) $quiz->category_id : null,
            ]))
            ->concat($course->assignments()->get(['id', 'title', 'category_id'])->map(fn (Assignment $assignment): array => [
                'key' => "assignment-{$assignment->id}",
                'type' => 'assignment',
                'id' => (int) $assignment->id,
                'title' => $assignment->title,
                'category_id' => $assignment->category_id !== null ? (int) $assignment->category_id : null,
            ]))
            ->sortBy(fn (array $assessment): string => strtolower($assessment['title']))
            ->values();

        return response()->json([
            'message' => 'Gradebook categories updated.',
            'assessments' => $assessments,
        ]);
    }

    /**
     * @return array{key: string, id: int, name: string, weight: float, sort_order: int}
     */
    private function payload(GradebookCategory $category): array
    {
        return [
            'key' => "category-{$category->id}",
            'id' => (int) $category->id,
            'name' => $category->name,
            'weight' => (float) $category->weight,
            'sort_order' => (int) $category->sort_order,
        ];
    }

    /**
     * @return array{0: 'quiz'|'assignment', 1: int}
     */
    private function splitKey(string $key): array
    {
        $parts = explode('-', $key);

        return [$parts[0], (int) $parts[1]];
    }
}
