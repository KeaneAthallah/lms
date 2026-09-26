<?php

namespace App\Models;

use App\QuizQuestionType;
use Database\Factories\QuizQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizQuestion extends Model
{
    /** @use HasFactory<QuizQuestionFactory> */
    use HasFactory;

    protected $fillable = ['quiz_id', 'type', 'question_text', 'explanation', 'points', 'sort_order', 'settings'];

    protected function casts(): array
    {
        return [
            'type' => QuizQuestionType::class,
            'points' => 'decimal:2',
            'settings' => 'array',
        ];
    }

    /**
     * The blank indexes present in the question text, in the order written.
     *
     * Both the grader and the student renderer need this, and they must agree
     * on it, so the placeholder syntax is defined in exactly one place.
     *
     * @return array<int, int>
     */
    public function blankIndexes(): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $this->question_text, $matches);

        $indexes = array_map('intval', $matches[1] ?? []);

        // array_values so a repeated placeholder collapses without leaving a gap
        // in the keys, which would break `foreach ($x as $i => ...)` in the UI.
        return array_values(array_unique($indexes));
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class)->orderBy('sort_order');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }
}
