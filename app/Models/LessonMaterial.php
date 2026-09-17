<?php

namespace App\Models;

use Database\Factories\LessonMaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonMaterial extends Model
{
    /** @use HasFactory<LessonMaterialFactory> */
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'filename',
        'path',
        'disk',
        'mime_type',
        'size',
        'type',
        'is_downloadable',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_downloadable' => 'boolean',
            'size' => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
