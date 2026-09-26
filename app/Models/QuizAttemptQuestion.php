<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per question served to one attempt.
 *
 * This is the freeze point for a quiz. Everything that grades, reviews, or
 * resumes an attempt reads through `QuizAttempt::questions()`, so an attempt
 * keeps seeing the questions the student was actually shown even if the bank
 * behind them is edited or the quiz's own question list changes.
 */
class QuizAttemptQuestion extends Model
{
    protected $fillable = ['quiz_attempt_id', 'quiz_question_id', 'sort_order'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
