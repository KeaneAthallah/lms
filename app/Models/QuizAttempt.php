<?php

namespace App\Models;

use App\QuizAttemptStatus;
use Database\Factories\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function isCompleted(): bool
    {
        return $this->status === QuizAttemptStatus::Completed;
    }
}
