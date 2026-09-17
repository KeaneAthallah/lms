<?php

namespace App\Models;

use Database\Factories\CourseSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseSection extends Model
{
    /** @use HasFactory<CourseSectionFactory> */
    use HasFactory;

    protected $fillable = ['course_id', 'title', 'description', 'sort_order'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'section_id')->orderBy('sort_order');
    }
}
