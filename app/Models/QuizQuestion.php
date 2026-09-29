<?php

namespace App\Models;

use App\QuizQuestionType;
use App\Services\QuestionEditor;
use Database\Factories\QuizQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizQuestion extends Model
{
    /** @use HasFactory<QuizQuestionFactory> */
    use HasFactory;

    protected $fillable = ['quiz_id', 'question_bank_id', 'replaced_by_id', 'version', 'type', 'question_text', 'explanation', 'points', 'sort_order', 'settings'];

    protected function casts(): array
    {
        return [
            'type' => QuizQuestionType::class,
            'points' => 'decimal:2',
            'version' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * A question belongs to a quiz or to a bank, or is a detached historical
     * version that a live one replaced (`replaced_by_id` set); never both owners.
     *
     * A bare row attached to nothing is a bug, and no write path creates one:
     * authoring goes through a quiz or a bank relation, and forking goes through
     * {@see QuestionEditor}, which sets `replaced_by_id` when it
     * detaches the old head.
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

            if ($hasQuiz && $hasBank) {
                throw new \LogicException('A question cannot belong to both a quiz and a question bank.');
            }

            if (! $hasQuiz && ! $hasBank && $question->replaced_by_id === null) {
                throw new \LogicException('A question must belong to either a quiz or a question bank.');
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
     * The newer version this row was superseded by.
     *
     * The currently-owned head has null; a detached historical version points at
     * the version that replaced it, so following the link walks the lineage
     * forward to the live question. Served attempts keep referencing the
     * detached rows directly through `attempt_snapshots`, which is what makes an
     * old version's text safe to edit.
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
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
