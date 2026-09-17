<?php

namespace App\Models;

use Database\Factories\QuizOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizOption extends Model
{
    /** @use HasFactory<QuizOptionFactory> */
    use HasFactory;

    protected $fillable = ['quiz_question_id', 'option_text', 'is_correct', 'explanation', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class);
    }
}
