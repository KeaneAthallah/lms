<?php

namespace App\Support\Grading;

use App\Models\QuizQuestion;

/**
 * Everything the assessment engine needs to know about one question type.
 *
 * Extracted from the `match` that used to live in `QuizService` so that a new
 * question type is a new class rather than a new arm in a growing switch, and so
 * that the scoring knobs Phase 2 introduces (partial credit, negative marking,
 * rubrics) have somewhere to live.
 *
 * The four methods correspond to the four things that vary by type:
 *
 * - `grade()`             how many points a response earns
 * - `serialize()`         how a response is stored in `quiz_answers.answer`
 * - `decode()`            how a stored answer is read back for the review report
 * - `settingsForStudent()` the subset of `settings` a student may see
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

    /**
     * Read a stored answer back into the shape the review report and the
     * diagnosis engine expect, so no caller has to branch on question type.
     *
     * @return int|float|string|array<int, mixed>|null
     */
    public function decode(?string $stored): mixed;

    /**
     * The subset of `$question->settings` that is safe to send to a student.
     *
     * This is the answer key for any type that stores one — the accepted
     * answers for a fill-in-the-blank, the accepted value and tolerance for a
     * numeric — so a type that keeps its answer inside `settings` must override
     * this rather than returning the bag wholesale.
     *
     * @return array<string, mixed>
     */
    public function settingsForStudent(QuizQuestion $question): array;
}
