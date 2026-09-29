<?php

namespace App\Models;

use App\QuizAttemptStatus;
use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'category_id',
        'question_bank_id',
        'draw_size',
        'title',
        'description',
        'instructions',
        'time_limit_minutes',
        'passing_score',
        'attempts_allowed',
        'status',
        'available_from',
        'available_until',
        'late_grace_minutes',
    ];

    protected function casts(): array
    {
        return [
            'passing_score' => 'decimal:2',
            'attempts_allowed' => 'integer',
            'time_limit_minutes' => 'integer',
            'draw_size' => 'integer',
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'late_grace_minutes' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function gradebookCategory(): BelongsTo
    {
        return $this->belongsTo(GradebookCategory::class, 'category_id');
    }

    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    /**
     * Whether this quiz draws a random subset of a bank instead of owning its
     * questions.
     */
    public function drawsFromBank(): bool
    {
        return $this->question_bank_id !== null;
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order');
    }

    /**
     * Per-type quotas shaping the bank draw. Empty for a quiz without a bank, or
     * for one that draws a uniformly random sample.
     */
    public function blueprintRules(): HasMany
    {
        return $this->hasMany(QuizBlueprintRule::class);
    }

    /**
     * Questions the blueprint pins down, as a floor rather than a total: the draw
     * still serves `draw_size` when the quotas leave room and the bank has the
     * questions to fill it.
     */
    public function blueprintQuestionCount(): int
    {
        $rules = $this->relationLoaded('blueprintRules')
            ? $this->blueprintRules
            : $this->blueprintRules()->get();

        return (int) $rules->sum('question_count');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function lesson(): HasOne
    {
        return $this->hasOne(Lesson::class);
    }

    public function totalPoints(): float
    {
        return (float) $this->questions->sum('points');
    }

    /**
     * How many questions a student will actually be served.
     *
     * `questions()->count()` is the wrong number for a bank quiz: it owns no
     * questions of its own, so it would report zero to every place that shows a
     * question count or estimates how long a lesson takes.
     */
    public function plannedQuestionCount(): int
    {
        if ($this->drawsFromBank()) {
            return (int) ($this->draw_size ?? 0);
        }

        // Prefer an already-loaded relation, then a `loadCount` result, so
        // rendering a course page does not fan out into a query per lesson.
        if ($this->relationLoaded('questions')) {
            return $this->questions->count();
        }

        return (int) ($this->questions_count ?? $this->questions()->count());
    }

    /**
     * How many of the student's allowed attempts are spent.
     *
     * An expired attempt counts. It did not used to, and that made a time limit
     * advisory: a student whose clock ran out left an `in_progress` row that
     * nothing ever closed, so it was never counted here and Start handed them
     * another full-duration paper. They could repeat that until they passed.
     * The attempt is still graded from whatever was autosaved, so the time
     * spent is not thrown away -- it just cannot be spent again.
     */
    public function attemptsFor(User $student): int
    {
        return $this->attempts()
            ->where('student_id', $student->id)
            ->whereIn('status', [
                QuizAttemptStatus::Completed->value,
                QuizAttemptStatus::Expired->value,
                // An attempt still in progress has spent its slot too. It has
                // not been closed, which usually only means nothing has looked
                // at it yet, and `start()` either resumes or closes it before
                // reading this number -- so counting it cannot block a student
                // from picking up their own unfinished paper.
                QuizAttemptStatus::InProgress->value,
            ])
            ->count();
    }

    /**
     * When this attempt's strict clock runs out, or null when nothing bounds it.
     *
     * This is the deadline the student races: the countdown the client renders
     * counts down to it, and handing in after it (but inside the late grace, see
     * {@see graceDeadlineFor()}) is a late submission rather than a failure.
     *
     * The strict deadline is the earlier of the time-limit clock counting from
     * the attempt's start and the hard close of the test window: a window that
     * closes before the clock runs down ends the attempt, and a window that
     * closes after it has no say. An attempt with no time limit and no window
     * close has no deadline at all.
     *
     * Returns a copy because `addMinutes()` mutates the Carbon it is called on,
     * and `started_at` is a cast attribute: adding the limit to it in place would
     * move the attempt's start time forward on the in-memory model.
     */
    public function deadlineFor(QuizAttempt $attempt): ?Carbon
    {
        $clocks = [];

        if ($this->time_limit_minutes) {
            $clocks[] = $attempt->started_at->copy()->addMinutes((int) $this->time_limit_minutes);
        }

        if ($this->available_until) {
            $clocks[] = $this->available_until;
        }

        return $clocks === [] ? null : collect($clocks)->min();
    }

    /**
     * The very last moment a late submission is still accepted, or null when the
     * quiz grants no grace. The strict deadline plus `late_grace_minutes`, so
     * the silent period between the two is when an attempt is finished and
     * handed in marked as late. An attempt that was never bounded has no grace
     * to speak of either -- a grace can only stretch a deadline that exists.
     */
    public function graceDeadlineFor(QuizAttempt $attempt): ?Carbon
    {
        $deadline = $this->deadlineFor($attempt);

        if ($deadline === null || ! $this->late_grace_minutes) {
            return null;
        }

        return $deadline->copy()->addMinutes((int) $this->late_grace_minutes);
    }

    /**
     * The moment past which the attempt is force-closed and its work scored as
     * it stands: the strict deadline when there is no grace, or the end of the
     * grace when there is. This is what "can the student still answer?" means,
     * so expiry, save acceptance and the resume offer all key off it.
     */
    public function answerableUntil(QuizAttempt $attempt): ?Carbon
    {
        return $this->graceDeadlineFor($attempt) ?? $this->deadlineFor($attempt);
    }

    /**
     * Where this quiz stands relative to its availability window at a moment in
     * time. A quiz without a window is always open.
     *
     * @return 'not_yet_open'|'open'|'closed'
     */
    public function availabilityAt(Carbon $at): string
    {
        if ($this->available_from && $this->available_from->isAfter($at)) {
            return 'not_yet_open';
        }

        if ($this->available_until && $this->available_until->isBefore($at)) {
            return 'closed';
        }

        return 'open';
    }

    /**
     * The student's unfinished attempt, whether or not it still has time.
     *
     * `start()` needs this to find the row it has to close when the clock ran
     * out, so it cannot be the resumable filter.
     */
    public function openAttemptFor(User $student): ?QuizAttempt
    {
        return $this->attempts()
            ->where('student_id', $student->id)
            ->where('status', QuizAttemptStatus::InProgress->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The attempt the overview should offer to pick up again.
     *
     * A row left `in_progress` by a student who closed the tab is not resumable
     * even though it is still open: there is nothing here to sweep it up, so its
     * deadline can pass unnoticed and it would sit open forever. Offering it
     * would promise work that clicking it cannot deliver.
     *
     * `answerableUntil()` rather than `deadlineFor()`, so an attempt inside its
     * late grace is still offered: the student's time has run out but the grace
     * period is exactly when they are meant to be able to finish.
     */
    public function resumableAttemptFor(User $student): ?QuizAttempt
    {
        $attempt = $this->openAttemptFor($student);

        if (! $attempt) {
            return null;
        }

        $until = $this->answerableUntil($attempt);

        return $until === null || $until->isFuture() ? $attempt : null;
    }

    public function bestAttemptFor(User $student): ?QuizAttempt
    {
        return $this->attempts()
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('score')
            ->first();
    }
}
