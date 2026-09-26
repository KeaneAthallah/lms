<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Grades `fill_in_blank`, where the question text embeds `{{n}}` placeholders
 * and `settings.blanks[n]` holds the accepted answers for blank `n`.
 *
 * Every blank may list several accepted answers, so an author can accept
 * "Laravel" and "the Laravel framework" without a fuzzy matcher. The
 * alternatives are an OR; the blanks are an AND.
 *
 * `settings.partial_credit` behaves the same as it does for multi-select, which
 * keeps one scoring knob across types: off means every blank must be right, on
 * means credit is proportional to the blanks answered correctly. A blank left
 * empty counts as incorrect rather than aborting the question, so a student who
 * gets 3 of 4 keeps proportional credit instead of nothing.
 */
class FillInBlankGrader implements Grader
{
    public function grade(QuizQuestion $question, mixed $submitted): float
    {
        $accepted = $this->acceptedAnswers($question);

        if ($accepted === []) {
            return 0.0;
        }

        $given = $this->normalizeSubmission($submitted);

        if ($given === []) {
            return 0.0;
        }

        $hits = 0;

        foreach ($accepted as $index => $alternatives) {
            $answer = $given[$index] ?? null;

            if ($answer === null) {
                continue;
            }

            foreach ($alternatives as $alternative) {
                if (TextComparison::matches($answer, $alternative)) {
                    $hits++;
                    break;
                }
            }
        }

        if ($hits === 0) {
            return 0.0;
        }

        if (! ($question->settings['partial_credit'] ?? false)) {
            return $hits === count($accepted) ? (float) $question->points : 0.0;
        }

        return round(($hits / count($accepted)) * (float) $question->points, 2);
    }

    public function serialize(mixed $submitted): string
    {
        $given = $this->normalizeSubmission($submitted);

        // Keyed by blank index rather than a positional list, so a student who
        // skips blank 1 and answers blank 2 still round-trips correctly.
        ksort($given);

        return (string) json_encode($given);
    }

    /**
     * @return array<int, string>
     */
    public function decode(?string $stored): mixed
    {
        if ($stored === null) {
            return [];
        }

        return $this->normalizeSubmission(json_decode($stored, true));
    }

    public function settingsForStudent(QuizQuestion $question): array
    {
        // `blanks` is the answer key. Only the scoring mode is disclosed.
        return [
            'partial_credit' => (bool) ($question->settings['partial_credit'] ?? false),
            'blank_count' => count($question->blankIndexes()),
        ];
    }

    /**
     * Accepted answers per blank, keyed by blank index, taken from settings and
     * restricted to blanks that actually appear in the question text.
     *
     * @return array<int, array<int, string>>
     */
    private function acceptedAnswers(QuizQuestion $question): array
    {
        $blanks = $question->settings['blanks'] ?? [];

        if (! is_array($blanks)) {
            return [];
        }

        $inText = $question->blankIndexes();

        $accepted = [];

        foreach ($blanks as $index => $alternatives) {
            if (! in_array((int) $index, $inText, true)) {
                continue;
            }

            $alternatives = is_array($alternatives) ? $alternatives : [$alternatives];

            $accepted[(int) $index] = array_values(array_filter(
                array_map('strval', $alternatives),
                fn (string $value): bool => trim($value) !== '',
            ));
        }

        return $accepted;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeSubmission(mixed $submitted): array
    {
        if ($submitted === null || $submitted === '') {
            return [];
        }

        if (! is_array($submitted)) {
            return [];
        }

        $given = [];

        foreach ($submitted as $index => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $given[(int) $index] = (string) $value;
        }

        return $given;
    }
}
