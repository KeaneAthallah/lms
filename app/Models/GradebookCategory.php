<?php

namespace App\Models;

use Database\Factories\GradebookCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradebookCategory extends Model
{
    /** @use HasFactory<GradebookCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'name',
        'weight',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class, 'category_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'category_id');
    }

    /**
     * Whether any assessment still names this category.
     *
     * Deleting a category in use would silently re-home its assessments to
     * "uncategorized" and move every affected course grade, so the delete is
     * refused instead.
     */
    public function inUse(): bool
    {
        return $this->quizzes()->exists() || $this->assignments()->exists();
    }
}
