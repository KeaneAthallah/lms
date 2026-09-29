<?php

namespace App\Models;

use App\GradeAdjustmentAction;
use Database\Factories\GradeAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeAdjustment extends Model
{
    /** @use HasFactory<GradeAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'grade_id',
        'student_id',
        'course_id',
        'action',
        'dropped',
        'score',
        'max_score',
        'percentage',
        'note',
        'adjusted_by',
        'adjusted_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => GradeAdjustmentAction::class,
            'dropped' => 'boolean',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
            'adjusted_at' => 'datetime',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    /**
     * An override exists only as a whole triple, so the flag is derived rather
     * than stored: a cell that reports `overridden` always has a score to show.
     */
    public function hasOverride(): bool
    {
        return $this->percentage !== null;
    }
}
