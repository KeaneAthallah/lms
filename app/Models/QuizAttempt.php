<?php

namespace App\Models;

use App\QuizAttemptStatus;
use Database\Factories\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    /** @use HasFactory<QuizAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'student_id',
        'status',
        'started_at',
        'submitted_at',
        'score',
        'score_percentage',
        'passed',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuizAttemptStatus::class,
            'score' => 'decimal:2',
            'score_percentage' => 'decimal:2',
            'passed' => 'boolean',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    /**
     * The questions this attempt was served, in the order they were served.
     *
     * Every read of "the questions for this attempt" must go through here rather
     * than `quiz.questions`. A bank quiz owns none of its questions, and even a
     * fixed quiz can have its list edited after a student started, so resolving
     * live would show a different paper than the one being graded.
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(QuizQuestion::class, 'quiz_attempt_questions', 'quiz_attempt_id', 'quiz_question_id')
            ->withPivot('sort_order')
            ->orderBy('quiz_attempt_questions.sort_order');
    }

    public function isCompleted(): bool
    {
        return $this->status === QuizAttemptStatus::Completed;
    }
}
