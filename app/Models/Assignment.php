<?php

namespace App\Models;

use App\AssignmentStatus;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'instructions',
        'due_at',
        'max_score',
        'allowed_file_types',
        'max_file_size_kb',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'max_score' => 'decimal:2',
            'allowed_file_types' => 'array',
            'max_file_size_kb' => 'integer',
            'status' => AssignmentStatus::class,
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function lesson(): HasOne
    {
        return $this->hasOne(Lesson::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Active->value);
    }

    public function submissionFor(User $student): ?AssignmentSubmission
    {
        return $this->submissions()->where('student_id', $student->id)->first();
    }
}
