<?php

namespace App\Models;

use App\CourseStatus;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'category_id',
        'title',
        'slug',
        'short_description',
        'description',
        'thumbnail_path',
        'level',
        'language',
        'duration_minutes',
        'status',
        'price',
        'learning_objectives',
        'requirements',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'price' => 'decimal:2',
            'learning_objectives' => 'array',
            'requirements' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('sort_order');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function prerequisites(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class);
    }

    public function prerequisiteFor(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class, 'prerequisite_course_id');
    }

    public function prerequisiteCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_prerequisites', 'course_id', 'prerequisite_course_id')
            ->withPivot('sort_order')
            ->orderBy('course_prerequisites.sort_order');
    }

    public function unlocks(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_prerequisites', 'prerequisite_course_id', 'course_id')
            ->withPivot('sort_order')
            ->orderBy('course_prerequisites.sort_order');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published->value);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Draft->value);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('short_description', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }

    public function scopeInCategory(Builder $query, int|string $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function isPublished(): bool
    {
        return $this->status === CourseStatus::Published;
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->instructor_id === (int) $user->id;
    }

    public function totalLessonsCount(): int
    {
        return $this->lessons()->published()->count();
    }
}
