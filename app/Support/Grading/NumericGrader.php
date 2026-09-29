<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Grades `numeric`, where the accepted value is `settings.answer`.
 *
 * The accepted value deliberately does *not* live in `quiz_options`. Every
 * option row is rendered to the student as a choice, so storing the answer
 * there would display the answer as one of the options. Keeping the key in
 * settings means the same hidden storage as `fill_in_blank`, and
 * {@see settingsForStudent()} is the only place that decides what leaks.
 *
 * `settings.tolerance` is an absolute margin of error. It exists so an author
 * can mark "9.8" as correct for a physics question without also accepting 9.9
 * or 9.7, and so a tolerance of 0.01 can accept a rounded currency answer.
 *
 * The comparison is symmetric: a tolerance of 0.5 accepts a submitted 9.5
 * against an expected 10 and a submitted 10 against an expected 9.5. Requiring
 * only one direction would let an author set a tolerance that silently does
 * nothing.
 */
class NumericGrader implements Grader
{
    use NegativeMarking;

    public function grade(QuizQuestion $question, mixed $submitted): float
    {
        $expected = $this->parse($question->settings['answer'] ?? null);

        if ($expected === null) {
            // No usable accepted answer: unanswerable, so award nothing.
            return 0.0;
        }

        $given = $this->parse($submitted);

        if ($given === null) {
            return 0.0;
        }

        $tolerance = abs((float) ($question->settings['tolerance'] ?? 0));

        return abs($given - $expected) <= $tolerance + PHP_FLOAT_EPSILON
            ? (float) $question->points
            : 0.0;
    }

    public function serialize(mixed $submitted): string
    {
        $value = $this->parse($submitted);

        return $value === null ? '' : (string) $value;
    }

    public function decode(?string $stored): mixed
    {
        return $stored === null ? null : $this->parse($stored);
    }

    public function settingsForStudent(QuizQuestion $question): array
    {
        // Both `answer` and `tolerance` are the answer key, and neither is sent.
        return $this->negativeMarkingForStudent($question);
    }

    /**
     * Parse a submitted value, tolerating the units people paste in.
     *
     * Only a *leading* number is read, so `9.81 m/s^2` yields 9.81 rather than
     * 9.812 — a naive strip-everything-but-digits pass keeps the `2` out of
     * `^2` and silently corrupts the answer. Thousands separators, decimals and
     * scientific notation are all supported within that leading token.
     *
     * Returns null for anything without a leading number, so a garbled answer
     * scores zero rather than being coerced to 0.
     */
    private function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $pattern = '/^\s*([-+]?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?(?:[eE][-+]?\d+)?)/';

        if (! preg_match($pattern, $value, $matches)) {
            return null;
        }

        $parsed = str_replace(',', '', $matches[1]);

        return is_numeric($parsed) ? (float) $parsed : null;
    }
}
