<?php

namespace App\Models;

use App\QuizQuestionType;
use Database\Factories\QuizBlueprintRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quota in a quiz's blueprint: "serve N questions of this type".
 *
 * A quiz with no rules draws a uniformly random sample of its bank. Rules turn
 * that into a paper with a required shape. The quota is a floor the draw tries
 * to meet rather than a promise: if the bank holds fewer questions of a type
 * than the rule asks for, the draw serves what exists, the same way a bank
 * smaller than `draw_size` serves everything it has.
 */
class QuizBlueprintRule extends Model
{
    /** @use HasFactory<QuizBlueprintRuleFactory> */
    use HasFactory;

    protected $fillable = ['quiz_id', 'question_type', 'question_count'];

    protected function casts(): array
    {
        return [
            'question_type' => QuizQuestionType::class,
            'question_count' => 'integer',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
