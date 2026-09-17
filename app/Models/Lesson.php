<?php

namespace App\Models;

use App\LessonType;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    protected $fillable = [
        'section_id',
        'course_id',
        'quiz_id',
        'assignment_id',
        'title',
        'type',
        'content',
        'video_path',
        'video_url',
        'external_url',
        'duration_seconds',
        'thumbnail_path',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'is_published' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(LessonMaterial::class)->orderBy('sort_order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
