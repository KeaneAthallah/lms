<?php

namespace App\Services;

use App\Http\Requests\Quiz\StoreQuizQuestionRequest;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\QuizQuestionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Authoring for quiz and bank questions, shared by both controllers so a fork
 * behaves identically wherever it happens.
 *
 * Versioning: a question an attempt has already been served cannot change in
 * place -- the attempt review and grading are rebuilt from the question row, so
 * rewriting it would claim the student was asked something they never saw.
 * Editing one instead mints a new version: the new row becomes the owner's
 * question (same quiz/bank, `version` + 1) and the old head is detached, its
 * `replaced_by_id` pointing at the newcomer so the lineage stays readable.
 * Served attempts keep referencing the detached version by id, so their history
 * is immutable while future papers use the new one. An unused question is still
 * edited in place, because nothing is served off it yet.
 */
class QuestionEditor
{
    public function create(Quiz|QuestionBank $owner, StoreQuizQuestionRequest $request): QuizQuestion
    {
        $question = $owner->questions()->create([
            'type' => $request->input('type'),
            'question_text' => $request->input('question_text'),
            'explanation' => $request->input('explanation'),
            'points' => $request->input('points'),
            'settings' => $this->settingsFor($request),
            'sort_order' => $request->input('sort_order')
                ?? ((int) $owner->questions()->max('sort_order') + 1),
        ]);

        $this->syncOptions($question, $request->input('options', []), $question->type);

        return $question;
    }

    /**
     * Save an edit, forking a new version when the question is in use.
     *
     * The write is one transaction so a fork can never detach the old head and
     * then fail to mint the new one -- the quiz would be left owning nothing.
     */
    public function update(QuizQuestion $question, StoreQuizQuestionRequest $request): QuizQuestion
    {
        if (! $question->isInUse()) {
            $question->update([
                'type' => $request->input('type'),
                'question_text' => $request->input('question_text'),
                'points' => $request->input('points'),
                'explanation' => $request->input('explanation'),
                'settings' => $this->settingsFor($request),
                'sort_order' => $request->input('sort_order') ?? $question->sort_order,
            ]);

            if ($request->has('options')) {
                $question->options()->delete();
                $this->syncOptions($question, $request->input('options', []), $question->type);
            }

            return $question;
        }

        return DB::transaction(function () use ($question, $request): QuizQuestion {
            $child = new QuizQuestion;
            $child->quiz_id = $question->quiz_id;
            $child->question_bank_id = $question->question_bank_id;
            $child->version = (int) $question->version + 1;
            $child->type = $request->input('type');
            $child->question_text = $request->input('question_text');
            $child->explanation = $request->input('explanation');
            $child->points = $request->input('points');
            $child->settings = $this->settingsFor($request);
            $child->sort_order = $request->input('sort_order') ?? $question->sort_order;
            $child->save();

            // Detach the old head. Future draws (and the quiz/bank question list)
            // resolve through the owner, so they pick up the new version; served
            // attempts keep the old row by id.
            $question->update([
                'replaced_by_id' => $child->id,
                'quiz_id' => null,
                'question_bank_id' => null,
            ]);

            $this->syncOptions($child, $request->input('options', []), $child->type);

            return $child;
        });
    }

    /**
     * Refuse to remove a question a student has already been served.
     *
     * Editing such a question forks a new version, but deleting it cannot be
     * repaired the same way: the served rows would point at a missing question
     * and the review page would have nothing to render.
     *
     * @throws ValidationException
     */
    public function assertDeletable(QuizQuestion $question): void
    {
        if (! $question->isInUse()) {
            return;
        }

        throw ValidationException::withMessages([
            'question' => ['This question has already been used in a student attempt, so it cannot be deleted. Editing it creates a new version that students who have not taken it yet will see.'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settingsFor(StoreQuizQuestionRequest $request): ?array
    {
        $settings = $request->input('settings');

        return is_array($settings) && $settings !== [] ? $settings : null;
    }

    /**
     * Replace the question's options with the submitted set.
     *
     * Correct flags are meaningless for the types that keep their answer key in
     * `settings`, so they are dropped rather than stored misleadingly.
     */
    private function syncOptions(QuizQuestion $question, array $options, QuizQuestionType $type): void
    {
        $usesOptionKey = in_array($type, [
            QuizQuestionType::MultipleChoice,
            QuizQuestionType::TrueFalse,
            QuizQuestionType::MultiSelect,
        ], true);

        foreach ($options as $option) {
            $question->options()->create([
                'option_text' => $option['option_text'],
                'is_correct' => $usesOptionKey && (bool) ($option['is_correct'] ?? false),
                'explanation' => $option['explanation'] ?? null,
            ]);
        }
    }
}
