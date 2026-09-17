<?php

namespace App\Models;

use App\QuizAttemptStatus;
use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'instructions',
        'time_limit_minutes',
        'passing_score',
        'attempts_allowed',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'passing_score' => 'decimal:2',
            'attempts_allowed' => 'integer',
            'time_limit_minutes' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function lesson(): HasOne
    {
        return $this->hasOne(Lesson::class);
    }

    public function totalPoints(): float
    {
        return (float) $this->questions->sum('points');
    }

    public function attemptsFor(User $student): int
    {
        return $this->attempts()
            ->where('student_id', $student->id)
            ->where('status', QuizAttemptStatus::Completed->value)
            ->count();
    }

    public function bestAttemptFor(User $student): ?QuizAttempt
    {
        return $this->attempts()
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('score')
            ->first();
    }
}
