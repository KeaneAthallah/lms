<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Grades a free-text `short_answer` against the option marked correct.
 *
 * The stored answer is compared case-insensitively after trimming, which is the
 * behaviour the existing quiz suite already pins. Exact alternatives can be
 * added as further correct options; `firstWhere` stops at the first match.
 */
class ShortAnswerGrader implements Grader
{
    public function grade(QuizQuestion $question, mixed $submitted): float
    {
        if ($submitted === null || $submitted === '') {
            return 0.0;
        }

        $correct = $question->options->firstWhere('is_correct', true);

        if (! $correct) {
            return 0.0;
        }

        return $this->normalize((string) $submitted) === $this->normalize($correct->option_text)
            ? (float) $question->points
            : 0.0;
    }

    public function serialize(mixed $submitted): string
    {
        return (string) $submitted;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
