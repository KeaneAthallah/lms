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

    protected $fillable = ['quiz_id', 'question_bank_id', 'type', 'question_text', 'explanation', 'points', 'sort_order', 'settings'];

    protected function casts(): array
    {
        return [
            'type' => QuizQuestionType::class,
            'points' => 'decimal:2',
            'settings' => 'array',
        ];
    }

    /**
     * A question belongs to a quiz or to a bank, never both and never neither.
     *
     * Enforced here rather than by a database CHECK because this schema has to
     * migrate on SQLite, which cannot add a CHECK constraint to an existing
     * table. Both columns being nullable is what makes the mistake possible, so
     * the invariant is asserted on every write path instead of trusted.
     *
     * @throws \LogicException
     */
    protected static function booted(): void
    {
        static::saving(function (self $question): void {
            $hasQuiz = $question->quiz_id !== null;
            $hasBank = $question->question_bank_id !== null;

            if ($hasQuiz === $hasBank) {
                throw new \LogicException($hasQuiz
                    ? 'A question cannot belong to both a quiz and a question bank.'
                    : 'A question must belong to either a quiz or a question bank.');
            }
        });
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

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    /**
     * Attempts this question was served in.
     */
    public function attemptSnapshots(): HasMany
    {
        return $this->hasMany(QuizAttemptQuestion::class, 'quiz_question_id');
    }

    /**
     * Whether the question was served to at least one student attempt.
     *
     * Once true, the question is frozen: editing it would change what a graded
     * attempt's review page says the student was asked.
     *
     * Reads the `withExists` flag when the caller loaded it, so listing a whole
     * bank's questions costs one extra query rather than one per question.
     */
    public function isInUse(): bool
    {
        if (array_key_exists('attempt_snapshots_exists', $this->attributes)) {
            return (bool) $this->attributes['attempt_snapshots_exists'];
        }

        return $this->attemptSnapshots()->exists();
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
