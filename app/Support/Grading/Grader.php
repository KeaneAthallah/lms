<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Grades one student's response to one question.
 *
 * Extracted from the `match` that used to live in `QuizService` so that a new
 * question type is a new class rather than a new arm in a growing switch, and so
 * that the scoring knobs Phase 2 introduces (partial credit, negative marking,
 * rubrics) have somewhere to live.
 *
 * Implementations must be side-effect free and must not assume the attempt is
 * still open; expiry and attempt-state checks belong to the caller.
 */
interface Grader
{
    /**
     * Points earned for this response, from 0 to `$question->points`.
     *
     * Partial credit is permitted, so the result is not necessarily all-or-nothing.
     */
    public function grade(QuizQuestion $question, mixed $submitted): float;

    /**
     * Normalise a response for storage in `quiz_answers.answer`.
     *
     * Must round-trip: feeding this back into {@see grade()} on a later pass
     * must produce the same result.
     */
    public function serialize(mixed $submitted): string;
}
