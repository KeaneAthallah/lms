<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Negative marking disclosure for the graders that support it.
 *
 * Every scored question type gets this, because a wrong *attempted* answer
 * deducts the same way regardless of how the correct answer is decided. The
 * deduction itself is applied once, in `QuizService::gradeAttempt()`, so it
 * does not live here -- that keeps one calculation instead of a copy per type.
 */
trait NegativeMarking
{
    /**
     * The fraction of the question's points a wrong answer deducts.
     *
     * Disclosed to students because it changes how their score is decided, the
     * same reason `partial_credit` is. It is not the answer key, so sending it
     * leaks nothing about which option is correct.
     *
     * @return array<string, mixed>
     */
    protected function negativeMarkingForStudent(QuizQuestion $question): array
    {
        return [
            'negative_marking' => round((float) ($question->settings['negative_marking'] ?? 0.0), 4),
        ];
    }
}
