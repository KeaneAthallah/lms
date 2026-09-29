<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Grades `multi_select`, where any number of options may be chosen.
 *
 * Two scoring modes, chosen by `settings.partial_credit`:
 *
 * - off (default, and the only option for the original types) — all or nothing.
 *   The chosen set must match the correct set exactly, so choosing one correct
 *   option out of three alongside two wrong ones scores zero.
 * - on — proportional. Credit is `(correct chosen - wrong chosen) / correct
 *   total`, clamped to the question's points. The wrong-pick term is what stops
 *   "select everything" from scoring full marks, which is the usual way
 *   students game a multi-select.
 *
 * Set comparison ignores order and duplicates: a student who ticks A then B is
 * answering the same thing as one who ticks B then A, and a duplicated id from
 * a double-click must not inflate the score.
 */
class MultiSelectGrader implements Grader
{
    use NegativeMarking;

    public function grade(QuizQuestion $question, mixed $submitted): float
    {
        $correctIds = $question->options
            ->where('is_correct', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // No option is marked correct: unanswerable, so award nothing rather
        // than divide by zero or default to a guess.
        if ($correctIds === []) {
            return 0.0;
        }

        $chosenIds = $this->normalizeChoice($submitted);

        if ($chosenIds === []) {
            return 0.0;
        }

        if (! ($question->settings['partial_credit'] ?? false)) {
            $setsMatch = count($chosenIds) === count($correctIds)
                && count(array_diff($chosenIds, $correctIds)) === 0;

            return $setsMatch ? (float) $question->points : 0.0;
        }

        $correctHits = count(array_intersect($chosenIds, $correctIds));
        $wrongPicks = count(array_diff($chosenIds, $correctIds));

        $ratio = ($correctHits - $wrongPicks) / count($correctIds);

        return round(max(0.0, min(1.0, $ratio)) * (float) $question->points, 2);
    }

    public function serialize(mixed $submitted): string
    {
        $ids = $this->normalizeChoice($submitted);

        sort($ids);

        return (string) json_encode($ids);
    }

    /**
     * @return array<int, int>
     */
    public function decode(?string $stored): mixed
    {
        if ($stored === null) {
            return [];
        }

        return $this->normalizeChoice(json_decode($stored, true));
    }

    public function settingsForStudent(QuizQuestion $question): array
    {
        // Partial credit is not the answer key, and disclosing the scoring mode
        // is fair — the student is being assessed differently and should know.
        return array_merge(
            ['partial_credit' => (bool) ($question->settings['partial_credit'] ?? false)],
            $this->negativeMarkingForStudent($question),
        );
    }

    /**
     * Coerce a submitted value into a deduplicated list of option ids.
     *
     * @return array<int, int>
     */
    private function normalizeChoice(mixed $submitted): array
    {
        if ($submitted === null || $submitted === '') {
            return [];
        }

        // Tolerate a single scalar, because a student who ticks exactly one box
        // should not have to submit an array for it to count.
        if (! is_array($submitted)) {
            $submitted = [$submitted];
        }

        $ids = array_map(fn ($id): int => (int) $id, $submitted);

        return array_values(array_unique($ids));
    }
}
