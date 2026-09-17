<?php

namespace App\Models;

use Database\Factories\LessonProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    /** @use HasFactory<LessonProgressFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'course_id',
        'lesson_id',
        'progress_percent',
        'video_position_seconds',
        'started_at',
        'completed_at',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'video_position_seconds' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
