<?php

namespace App\Http\Requests\Concerns;

use App\Models\QuestionBank;
use App\Models\Quiz;
use App\QuizQuestionType;
use Illuminate\Support\Facades\DB;
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
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', $this->windowOrderRule()],
            'question_bank_id' => ['nullable', 'integer', $this->bankBelongsToCourseRule()],
            'draw_size' => ['nullable', 'integer', 'min:1'],
            'blueprint' => ['nullable', 'array'],
            'blueprint.*.type' => ['required', Rule::in($this->questionTypes())],
            // Zero is accepted, not a quota: clearing a field in the form is the
            // natural way to drop a rule, and the row is then not stored. The
            // unique index and the duplicate check would otherwise turn an
            // ordinary edit into a validation error.
            'blueprint.*.count' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<string>
     */
    private function questionTypes(): array
    {
        return array_column(QuizQuestionType::cases(), 'value');
    }

    /**
     * A window that never opens cannot hold an exam: the close has to come after
     * the open. Checked only when both ends are filled in.
     */
    private function windowOrderRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $from = $this->input('available_from');

            if ($from === null || $from === '' || $value === null) {
                return;
            }

            if (strtotime($value) < strtotime($from)) {
                $fail('The available-until must be after the available-from date.');
            }
        };
    }

    /**
     * The submitted quotas, normalised to a list that remembers where each row
     * came from.
     *
     * The index is kept so a rejected quota is reported on the row the author
     * actually typed it into, rather than on a key they never sent. A duplicated
     * type cannot reach the table, which has a unique index on
     * `(quiz_id, question_type)`, and is reported as a validation error rather
     * than surfacing as a failed insert.
     *
     * @return list<array{index: int|string, type: string, count: int}>
     */
    private function requestedBlueprint(): array
    {
        $requested = [];
        $seen = [];

        foreach ((array) $this->input('blueprint', []) as $index => $row) {
            if (! is_array($row) || ! isset($row['type'])) {
                continue;
            }

            $type = (string) $row['type'];
            $count = isset($row['count']) ? (int) $row['count'] : 0;

            if (isset($seen[$type])) {
                $this->blueprintDuplicates[] = $type;

                continue;
            }

            $seen[$type] = true;

            if ($count > 0) {
                $requested[] = ['index' => $index, 'type' => $type, 'count' => $count];
            }
        }

        return $requested;
    }

    /**
     * @var list<string>
     */
    private array $blueprintDuplicates = [];

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateDrawConfiguration($validator);
            $this->validateBlueprint($validator);
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
     * A blueprint is only meaningful for a quiz that draws a bank, and its quotas
     * have to describe a paper the bank can actually produce.
     */
    private function validateBlueprint(Validator $validator): void
    {
        $requested = $this->requestedBlueprint();

        foreach ($this->blueprintDuplicates as $type) {
            $validator->errors()->add('blueprint', "This question type is listed more than once: {$type}.");
        }

        if ($requested === []) {
            return;
        }

        $bankId = $this->input('question_bank_id');

        if ($bankId === null) {
            $validator->errors()->add('blueprint', 'A blueprint only applies to a quiz that draws from a question bank.');

            return;
        }

        $total = array_sum(array_column($requested, 'count'));
        $drawSize = (int) $this->input('draw_size');

        // The leftovers of the paper are filled from the types the blueprint did
        // not name, so quotas above the paper length are not a shortfall but a
        // contradiction: there is no room left to serve them.
        if ($total > $drawSize) {
            $validator->errors()->add(
                'blueprint',
                "The blueprint asks for {$total} questions but each attempt only draws {$drawSize}. Lower the quotas or raise the questions per attempt.",
            );

            return;
        }

        $bank = QuestionBank::query()->find($bankId);

        $available = $bank?->questions()
            ->select('type', DB::raw('count(*) as aggregate'))
            ->groupBy('type')
            ->pluck('aggregate', 'type') ?? collect();

        foreach ($requested as $rule) {
            $stock = (int) ($available[$rule['type']] ?? 0);

            if ($rule['count'] > $stock) {
                $validator->errors()->add(
                    "blueprint.{$rule['index']}.count",
                    $stock === 0
                        ? 'That question bank has no questions of this type.'
                        : "That question bank only has {$stock} question(s) of this type.",
                );
            }
        }
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
