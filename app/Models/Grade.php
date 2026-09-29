<?php

namespace App\Models;

use Database\Factories\GradeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Grade extends Model
{
    /** @use HasFactory<GradeFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'course_id',
        'source_type',
        'source_id',
        'type',
        'score',
        'max_score',
        'percentage',
        'feedback',
        'graded_by',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    /**
     * Every instructor decision ever made about this grade, oldest first.
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(GradeAdjustment::class)->orderBy('id');
    }

    /**
     * The decision currently in force: the newest row of the chain.
     *
     * Eager loaded wherever more than one grade is read, so resolving the state
     * of every cell costs one query for the whole course rather than one per
     * cell.
     */
    public function latestAdjustment(): HasOne
    {
        return $this->hasOne(GradeAdjustment::class)->latestOfMany();
    }

    /**
     * The adjustment in force as a plain state, falling back to the recorded
     * ledger row for the parts it does not touch.
     *
     * `null` scores mean "no override", not "a zero override": a cleared override
     * has to fall back to what the grader recorded, or clearing a zero would
     * read as a zero.
     *
     * @return array{dropped: bool, score: float|null, max_score: float|null, percentage: float|null, note: string|null}
     */
    public function adjustmentState(): array
    {
        $adjustment = $this->latestAdjustment;

        return [
            'dropped' => (bool) ($adjustment?->dropped ?? false),
            'score' => $adjustment?->hasOverride() ? (float) $adjustment->score : null,
            'max_score' => $adjustment?->hasOverride() ? (float) $adjustment->max_score : null,
            'percentage' => $adjustment?->hasOverride() ? (float) $adjustment->percentage : null,
            'note' => $adjustment?->note,
        ];
    }

    public function isDropped(): bool
    {
        return $this->adjustmentState()['dropped'];
    }

    public function isOverridden(): bool
    {
        return $this->adjustmentState()['percentage'] !== null;
    }

    /**
     * The score the course reports, which is the override when there is one.
     */
    public function effectiveScore(): float
    {
        return $this->adjustmentState()['score'] ?? (float) $this->score;
    }

    public function effectiveMaxScore(): float
    {
        return $this->adjustmentState()['max_score'] ?? (float) $this->max_score;
    }

    public function effectivePercentage(): float
    {
        return $this->adjustmentState()['percentage'] ?? (float) $this->percentage;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
