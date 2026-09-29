<?php

namespace App\Http\Requests\Gradebook;

use App\Http\Requests\Concerns\AuthorizesContentAuthors;
use App\Models\Assignment;
use App\Models\GradebookCategory;
use App\Models\Quiz;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The bulk "which assessment belongs to which bucket" redraw for one course.
 *
 * A selection is a column key (`quiz-3`, `assignment-7`) plus a category id or
 * null to send it back to "uncategorized". Both ends are verified against the
 * course: a key names an assessment this course actually owns and a category id
 * names a bucket this course actually owns, so a stray id from another course
 * cannot be attached by guessing.
 */
class AssignAssessmentsRequest extends FormRequest
{
    use AuthorizesContentAuthors;

    public function rules(): array
    {
        return [
            'selections' => ['required', 'array', 'max:200'],
            'selections.*.key' => ['required', 'string', 'max:64'],
            'selections.*.category_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('selections')) {
                return;
            }

            $courseId = (int) $this->route('course')->id;
            $selections = (array) $this->input('selections', []);
            $malformed = [];

            foreach ($selections as $index => $selection) {
                $split = $this->splitKey((string) $selection['key']);

                if ($split[0] === null) {
                    $malformed[] = $index;
                }
            }

            foreach ($malformed as $index) {
                $validator->errors()->add("selections.{$index}.key", 'A selection key must name a quiz or an assignment (e.g. quiz-3).');
            }

            $quizIds = $assignmentIds = [];
            foreach ($selections as $index => $selection) {
                if (in_array($index, $malformed, true)) {
                    continue;
                }

                [$type, $id] = $this->splitKey((string) $selection['key']);

                if ($type === 'quiz') {
                    $quizIds[] = $id;
                } else {
                    $assignmentIds[] = $id;
                }
            }

            $ownedQuizzes = Quiz::query()->where('course_id', $courseId)
                ->when($quizIds !== [], fn ($query) => $query->whereIntegerInRaw('id', $quizIds))
                ->pluck('id');
            $ownedAssignments = Assignment::query()->where('course_id', $courseId)
                ->when($assignmentIds !== [], fn ($query) => $query->whereIntegerInRaw('id', $assignmentIds))
                ->pluck('id');

            foreach ($selections as $index => $selection) {
                if (in_array($index, $malformed, true)) {
                    continue;
                }

                [$type, $id] = $this->splitKey((string) $selection['key']);

                $owned = $type === 'quiz' ? $ownedQuizzes : $ownedAssignments;

                if (! $owned->contains($id)) {
                    $validator->errors()->add("selections.{$index}.key", 'This assessment does not belong to the course.');
                }
            }

            $categoryIds = collect($selections)->pluck('category_id')->filter()->unique()->all();
            $ownedCategories = GradebookCategory::query()
                ->where('course_id', $courseId)
                ->when($categoryIds !== [], fn ($query) => $query->whereIntegerInRaw('id', $categoryIds))
                ->pluck('id');

            foreach ($selections as $index => $selection) {
                $categoryId = $selection['category_id'] ?? null;

                if ($categoryId !== null && ! $ownedCategories->contains((int) $categoryId)) {
                    $validator->errors()->add("selections.{$index}.category_id", 'This category does not belong to the course.');
                }
            }
        });
    }

    /**
     * @return array{0: 'quiz'|'assignment'|null, 1: int|null}
     */
    private function splitKey(string $key): array
    {
        $parts = explode('-', $key);

        if (count($parts) !== 2 || ! in_array($parts[0], ['quiz', 'assignment'], true) || ! ctype_digit($parts[1])) {
            return [null, null];
        }

        return [$parts[0], (int) $parts[1]];
    }
}
