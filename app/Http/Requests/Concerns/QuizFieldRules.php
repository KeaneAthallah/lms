<?php

namespace App\Http\Requests\Concerns;

use App\Models\QuestionBank;
use App\Models\Quiz;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared field rules for creating and updating a quiz.
 *
 * The store and update requests were byte-identical, and the question-bank
 * fields add a cross-field rule (a bank needs a draw size, and the draw size
 * cannot exceed what the bank holds) that would otherwise be written twice and
 * drift apart.
 */
trait QuizFieldRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'passing_score' => ['required', 'numeric', 'between:0,100'],
            'attempts_allowed' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'question_bank_id' => ['nullable', 'integer', $this->bankBelongsToCourseRule()],
            'draw_size' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateDrawConfiguration($validator);
        });
    }

    /**
     * A bank is course-scoped, and an instructor manages one course at a time
     * here, so a bank from another course must not be attachable even by id.
     */
    private function bankBelongsToCourseRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null) {
                return;
            }

            $courseId = (int) $this->route('course')?->id;

            if ($courseId === 0) {
                $fail('The selected question bank could not be verified.');

                return;
            }

            $exists = QuestionBank::query()
                ->whereKey($value)
                ->where('course_id', $courseId)
                ->exists();

            if (! $exists) {
                $fail('The selected question bank does not belong to this course.');
            }
        };
    }

    /**
     * A bank quiz draws a sample at start, so the draw size has to be a real
     * number that the bank can actually satisfy.
     */
    private function validateDrawConfiguration(Validator $validator): void
    {
        $bankId = $this->input('question_bank_id');
        $drawSize = $this->input('draw_size');

        if ($bankId === null) {
            // A quiz without a bank keeps its own questions, and a stray draw
            // size would be stored but never read.
            if ($drawSize !== null) {
                $validator->errors()->add('draw_size', 'A draw size only applies to a quiz that draws from a question bank.');
            }

            return;
        }

        if ($drawSize === null || $drawSize === '') {
            $validator->errors()->add('draw_size', 'Choose how many questions to draw from the bank.');

            return;
        }

        $available = (int) QuestionBank::query()->whereKey($bankId)->withCount('questions')->value('questions_count');

        if ((int) $drawSize > $available) {
            $validator->errors()->add(
                'draw_size',
                $available === 0
                    ? 'That question bank has no questions yet.'
                    : "That question bank only has {$available} question(s) to draw from.",
            );
        }

        $this->validateNoAttachedQuestions($validator);
    }

    /**
     * A quiz serves either its own questions or a bank's, never a silent mixture.
     *
     * Switching to a bank is refused rather than applied, because the attached
     * questions may already have been sat by students and cannot be removed
     * without losing those attempts.
     */
    private function validateNoAttachedQuestions(Validator $validator): void
    {
        $quiz = $this->route('quiz');

        if (! $quiz instanceof Quiz) {
            return;
        }

        if ($quiz->question_bank_id === (int) $this->input('question_bank_id')) {
            return;
        }

        $attached = $quiz->questions()->count();

        if ($attached > 0) {
            $validator->errors()->add(
                'question_bank_id',
                "This quiz still has {$attached} question(s) of its own. Remove them before switching to a question bank.",
            );
        }
    }
}
