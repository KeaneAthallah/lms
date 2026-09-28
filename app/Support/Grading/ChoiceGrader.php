<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Grades a single-choice question: `multiple_choice` and `true_false`.
 *
 * True/false needs no special handling — it is a two-option choice question,
 * and the instructor's options carry the answer.
 */
class ChoiceGrader implements Grader
{
    public function grade(QuizQuestion $question, mixed $submitted): float
    {
        if ($submitted === null || $submitted === '') {
            return 0.0;
        }

        $correct = $question->options->firstWhere('is_correct', true);

        if (! $correct) {
            // A question with no option marked correct is unanswerable, so it
            // awards nothing rather than defaulting to marking the first option
            // right. Surfacing this is an authoring bug, not a student one.
            return 0.0;
        }

        return $correct->id === (int) $submitted ? (float) $question->points : 0.0;
    }

    public function serialize(mixed $submitted): string
    {
        // Unanswered is the empty string, not `'0'`. Casting null to an int
        // produced `'0'`, which is a real option id in every database that
        // starts its tables at 1 and so happens to be harmless for grading --
        // but it does not read back as unanswered. Autosave restores a draft
        // through `decode()`, and `'0'` would come back as a selection, so a
        // student who closed the tab before answering would be told they had
        // answered the question.
        if ($submitted === null || $submitted === '') {
            return '';
        }

        return (string) ((int) $submitted);
    }

    public function decode(?string $stored): mixed
    {
        return $stored === null || $stored === '' ? null : (int) $stored;
    }

    public function settingsForStudent(QuizQuestion $question): array
    {
        return [];
    }
}
