<?php

namespace App\Models;

use Database\Factories\QuizAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAnswer extends Model
{
    /** @use HasFactory<QuizAnswerFactory> */
    use HasFactory;

    protected $fillable = ['quiz_attempt_id', 'quiz_question_id', 'answer', 'is_correct', 'points_earned'];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'points_earned' => 'decimal:2',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class);
    }
}
