<?php

namespace App\Models;

use Database\Factories\CoursePrerequisiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoursePrerequisite extends Model
{
    /** @use HasFactory<CoursePrerequisiteFactory> */
    use HasFactory;

    protected $fillable = ['course_id', 'prerequisite_course_id', 'sort_order'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'prerequisite_course_id');
    }
}
