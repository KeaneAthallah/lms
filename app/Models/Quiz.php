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
        'question_bank_id',
        'draw_size',
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
            'draw_size' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    /**
     * Whether this quiz draws a random subset of a bank instead of owning its
     * questions.
     */
    public function drawsFromBank(): bool
    {
        return $this->question_bank_id !== null;
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order');
    }

    /**
     * Per-type quotas shaping the bank draw. Empty for a quiz without a bank, or
     * for one that draws a uniformly random sample.
     */
    public function blueprintRules(): HasMany
    {
        return $this->hasMany(QuizBlueprintRule::class);
    }

    /**
     * Questions the blueprint pins down, as a floor rather than a total: the draw
     * still serves `draw_size` when the quotas leave room and the bank has the
     * questions to fill it.
     */
    public function blueprintQuestionCount(): int
    {
        $rules = $this->relationLoaded('blueprintRules')
            ? $this->blueprintRules
            : $this->blueprintRules()->get();

        return (int) $rules->sum('question_count');
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

    /**
     * How many questions a student will actually be served.
     *
     * `questions()->count()` is the wrong number for a bank quiz: it owns no
     * questions of its own, so it would report zero to every place that shows a
     * question count or estimates how long a lesson takes.
     */
    public function plannedQuestionCount(): int
    {
        if ($this->drawsFromBank()) {
            return (int) ($this->draw_size ?? 0);
        }

        // Prefer an already-loaded relation, then a `loadCount` result, so
        // rendering a course page does not fan out into a query per lesson.
        if ($this->relationLoaded('questions')) {
            return $this->questions->count();
        }

        return (int) ($this->questions_count ?? $this->questions()->count());
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
