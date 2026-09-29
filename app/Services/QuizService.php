<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\QuizBlueprintRule;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Notifications\QuizResult;
use App\QuizAttemptStatus;
use App\QuizQuestionType;
use App\Support\Grading\Grader;
use App\Support\Grading\GraderRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuizService
{
    public function __construct(
        protected ProgressService $progress,
        protected GraderRegistry $graders,
    ) {}

    public function canStart(Quiz $quiz, User $student): bool
    {
        if ((int) $quiz->attempts_allowed === 0) {
            return true;
        }

        return $quiz->attemptsFor($student) < (int) $quiz->attempts_allowed;
    }

    /**
     * The student-facing reason a quiz's window will not let a new attempt begin.
     *
     * @param  'not_yet_open'|'open'|'closed'  $availability
     */
    public function unavailableMessage(Quiz $quiz, string $availability): string
    {
        if ($availability === 'not_yet_open') {
            return 'This quiz opens on '.$quiz->available_from?->format('M j, Y g:i A').' and is not available yet.';
        }

        return 'This quiz closed on '.$quiz->available_until?->format('M j, Y g:i A').' and is no longer available.';
    }

    /**
     * Begin an attempt, or hand back the one already in progress.
     *
     * @throws ValidationException when the window is shut, the student has no
     *                             attempts left, or the quiz has nothing to serve
     */
    public function start(Quiz $quiz, User $student): QuizAttempt
    {
        // The attempt limit is a check-then-insert, so the quiz row is locked for
        // the duration. Without the lock two clicks (or two tabs) both read
        // "one attempt used" and both insert, exceeding `attempts_allowed`.
        //
        // The rejections are returned from the transaction and raised outside it,
        // for the same reason the window rejection is: a quiz that closed while an
        // attempt was in flight closes that attempt *first* (see below), and a
        // simultaneous run-out of attempts must not roll that close back with the
        // error. Throwing before the commit would undo the expired attempt's close.
        $outcome = DB::transaction(function () use ($quiz, $student): array {
            Quiz::whereKey($quiz->id)->lockForUpdate()->first();

            // An attempt the student can still answer is resumed, never replaced.
            // Minting a second paper here was how a time limit came to be
            // advisory: the abandoned attempt stayed `in_progress` forever, so it
            // never counted against `attempts_allowed` and Start kept handing
            // over fresh full-duration draws.
            if ($live = $quiz->openAttemptFor($student)) {
                if (! $this->hasExpired($live, $quiz)) {
                    return ['attempt' => $live];
                }

                // Out of time, and nobody was there to notice. Close it here so
                // the saved work is scored and recorded rather than left open and
                // invisible, and so it counts against `attempts_allowed`.
                $this->closeExpired($live);
            }

            // The window is a gate on *new* attempts, checked after the resume
            // above so that a closed window still grades the attempt it noticed
            // a moment ago (that is what the deadline clamp does) rather than
            // locking the student out of their own result.
            $availability = $quiz->availabilityAt(now());

            if ($availability !== 'open') {
                return ['availability' => $availability];
            }

            if (! $this->canStart($quiz, $student)) {
                return ['failure' => 'attempts'];
            }

            $questions = $this->resolveQuestions($quiz);

            if ($questions->isEmpty()) {
                return ['failure' => 'questions'];
            }

            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'status' => QuizAttemptStatus::InProgress,
                'started_at' => now(),
            ]);

            $this->freezeQuestionsFor($attempt, $questions);

            if ($quiz->lesson) {
                $this->progress->markStarted($quiz->lesson, $student);
            }

            return ['attempt' => $attempt];
        });

        if (isset($outcome['availability'])) {
            throw ValidationException::withMessages([
                'attempts' => [$this->unavailableMessage($quiz, $outcome['availability'])],
            ]);
        }

        if (($outcome['failure'] ?? null) === 'attempts') {
            throw ValidationException::withMessages([
                'attempts' => ['You have used all of your allowed attempts for this quiz.'],
            ]);
        }

        if (($outcome['failure'] ?? null) === 'questions') {
            throw ValidationException::withMessages([
                'quiz' => [$quiz->drawsFromBank()
                    ? 'This quiz has no questions available yet.'
                    : 'This quiz has no questions yet.'],
            ]);
        }

        return $outcome['attempt'];
    }

    /**
     * Store answers the student has given but not yet submitted.
     *
     * The rows are written to `quiz_answers` ungraded, so a draft is a row with
     * no verdict on it and `submit()` overwrites it with the graded result. One
     * table means one source of truth: there is no second copy to fall out of
     * step with the first, and the unique (attempt, question) index makes the
     * upsert idempotent, which is what lets a debounced client retry a save it
     * never saw the response to.
     *
     * @param  array<int, array{question_id: int, answer: mixed}>  $answers
     *
     * @throws ValidationException when the attempt is graded or out of time
     */
    public function saveDrafts(QuizAttempt $attempt, array $answers): QuizAttempt
    {
        // Same lock as `submit()`: a save landing after the final submit would
        // otherwise blank the graded answer it was racing.
        //
        // The rejection is decided inside the transaction and raised outside it.
        // Throwing from within would roll the close back with everything else,
        // which is exactly what the expired branch below needs to keep.
        $rejection = DB::transaction(function () use ($attempt, $answers): ?string {
            $locked = QuizAttempt::whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // `isGraded()`, not the status, for the same reason `submit()` uses
            // it: an expired attempt is graded, so a status check would let a
            // save overwrite the result.
            if ($locked->isGraded()) {
                return 'submitted';
            }

            if ($this->hasExpired($locked)) {
                // Late save: score what did get saved. Without the close the
                // drafts would sit here ungraded until some later request
                // noticed, and the student would never see the score for the
                // time they did spend.
                $this->closeExpired($locked);

                return 'expired';
            }

            // The frozen set, for the same reason grading uses it: a question
            // the student was never served has no row to grade, and one deleted
            // from the quiz mid-attempt must not be resurrected as a draft.
            $questions = $locked->questions()->with('options')->get()->keyBy('id');

            foreach ($answers as $item) {
                $questionId = (int) ($item['question_id'] ?? 0);

                // A question outside the frozen set is dropped rather than
                // rejected, so one bad id cannot cost the student the rest of
                // their work. Grading never reads the row, so storing it would
                // buy nothing.
                if (! $questionId || ! $questions->has($questionId)) {
                    continue;
                }

                QuizAnswer::updateOrCreate(
                    ['quiz_attempt_id' => $locked->id, 'quiz_question_id' => $questionId],
                    [
                        'answer' => $this->serializeAnswer($questions->get($questionId), $item['answer'] ?? null),
                        'is_correct' => null,
                        'points_earned' => null,
                    ],
                );
            }

            return null;
        });

        if ($rejection !== null) {
            throw ValidationException::withMessages([
                'attempt' => [$rejection === 'submitted'
                    ? 'This attempt has already been submitted.'
                    : 'This attempt has expired.'],
            ]);
        }

        return $attempt->refresh();
    }

    /**
     * The saved answers for an unfinished attempt, decoded the way the client
     * holds them, keyed by question id.
     *
     * Decoding through the grader rather than passing the stored string back is
     * what lets the client treat a resumed attempt exactly like a fresh one: a
     * multi-select comes back as an array and a fill-in-the-blank as a map, not
     * as the JSON text the database holds.
     *
     * @return array<int, mixed>
     */
    public function draftAnswers(QuizAttempt $attempt): array
    {
        return $attempt->answers()
            ->with('question')
            ->get()
            ->mapWithKeys(fn (QuizAnswer $answer): array => [
                $answer->quiz_question_id => $this->graders->for($answer->question->type)->decode($answer->answer),
            ])
            ->all();
    }

    /**
     * The questions this attempt should be served.
     *
     * For an ordinary quiz that is its own list. For a bank quiz it is a random
     * sample, which is the whole point of a bank: two students sitting the same
     * quiz get different questions.
     *
     * A bank with fewer questions than `draw_size` serves everything it has
     * rather than refusing to start. Failing here would lock a student out of a
     * quiz over an instructor's housekeeping.
     *
     * @return Collection<int, QuizQuestion>
     */
    public function resolveQuestions(Quiz $quiz): Collection
    {
        if (! $quiz->drawsFromBank()) {
            return $quiz->questions()->with('options')->get();
        }

        $bank = $quiz->questionBank;

        if (! $bank) {
            return collect();
        }

        $rules = $quiz->relationLoaded('blueprintRules')
            ? $quiz->blueprintRules
            : $quiz->blueprintRules()->orderBy('question_type')->get();

        $pickedIds = $rules->isEmpty()
            ? $this->randomDraw($bank, max(1, (int) ($quiz->draw_size ?? 0)))
            : $this->blueprintDraw($quiz, $bank, $rules);

        return $this->loadPackedQuestions($bank, $pickedIds);
    }

    /**
     * An unconstrained sample of the bank, in the order it will be served.
     *
     * @return list<int>
     */
    private function randomDraw(QuestionBank $bank, int $limit): array
    {
        return $bank->questions()
            // `reorder()` drops the relation's default `sort_order`, which would
            // otherwise win: SQL only honours the first ORDER BY, so adding
            // `inRandomOrder()` on top of it looks random in the code and serves
            // the same first N questions to every student.
            ->reorder()
            ->inRandomOrder()
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Draw the blueprint's quotas, then fill whatever is left of `draw_size` from
     * the types the blueprint did not name.
     *
     * The fill deliberately avoids the pinned types. Filling from the whole bank
     * would let a paper of eight draw "two multiple-choice" and then serve four
     * anyway, so the blueprint would describe nothing: the author asking for a
     * shape gets the shape.
     *
     * A quota is still a floor on availability, not a promise. If the bank holds
     * fewer questions of a type than the rule asks for, the draw serves what
     * exists rather than failing the attempt, the same way a bank smaller than
     * `draw_size` does.
     *
     * Each step is its own query rather than one query over the whole bank, so
     * the cost is bounded by the size of the blueprint (at most six quotas plus
     * two fills) instead of by how much the instructor has written.
     *
     * @param  Collection<int, QuizBlueprintRule>  $rules
     * @return list<int>
     */
    private function blueprintDraw(Quiz $quiz, QuestionBank $bank, Collection $rules): array
    {
        $pickedIds = [];
        $pinnedTypes = [];
        $pinned = 0;

        foreach ($rules as $rule) {
            $quota = max(0, (int) $rule->question_count);

            if ($quota === 0) {
                continue;
            }

            $batch = $bank->questions()
                ->where('type', $rule->question_type->value)
                // A question already claimed by an earlier quota must not be
                // counted twice, or a bank with overlapping rules would serve
                // the same item twice in one paper.
                ->when($pickedIds !== [], fn ($query) => $query->whereNotIn('id', $pickedIds))
                ->reorder()
                ->inRandomOrder()
                ->limit($quota)
                ->pluck('id')
                ->all();

            $pickedIds = [...$pickedIds, ...$batch];
            $pinnedTypes[] = $rule->question_type->value;
            $pinned += count($batch);
        }

        $remaining = max(1, (int) ($quiz->draw_size ?? 0)) - $pinned;

        if ($remaining <= 0) {
            return $pickedIds;
        }

        $fill = $this->pickFrom($bank, $remaining, $pickedIds, $pinnedTypes);
        $pickedIds = [...$pickedIds, ...$fill];

        // If the blueprint names every type the bank holds and a quota came up
        // short, there is nothing left to fill with. A paper shorter than the
        // instructor asked for is the worse outcome, so the shortfall is topped
        // up from anything remaining rather than left on the table.
        if (count($fill) < $remaining) {
            $pickedIds = [
                ...$pickedIds,
                ...$this->pickFrom($bank, $remaining - count($fill), $pickedIds, []),
            ];
        }

        return $pickedIds;
    }

    /**
     * @param  list<int>  $excludeIds
     * @param  list<string>  $excludeTypes
     * @return list<int>
     */
    private function pickFrom(QuestionBank $bank, int $limit, array $excludeIds, array $excludeTypes): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $bank->questions()
            ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->when($excludeTypes !== [], fn ($query) => $query->whereNotIn('type', $excludeTypes))
            ->reorder()
            ->inRandomOrder()
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Hydrate the drawn ids with their options, served in a random order.
     *
     * Randomising the order matters as much as randomising the selection: a
     * blueprint is applied type by type, so serving it in rule order would hand
     * every student the same predictable run of multiple-choice followed by
     * numeric, and the paper would read as sorted rather than sampled. That also
     * makes the order the draw picked them in irrelevant here.
     *
     * An empty `$pickedIds` needs no special case: `whereIn` with no ids matches
     * nothing, so a bank that cannot satisfy any quota serves an empty paper
     * instead of the whole bank.
     *
     * @param  list<int>  $pickedIds
     * @return Collection<int, QuizQuestion>
     */
    private function loadPackedQuestions(QuestionBank $bank, array $pickedIds): Collection
    {
        return $bank->questions()
            ->with('options')
            ->whereIn('id', $pickedIds)
            ->get()
            ->shuffle()
            ->values();
    }

    /**
     * Record the served questions against the attempt.
     *
     * From this point the attempt is graded, reviewed, and resumed through
     * `QuizAttempt::questions()`, so the paper cannot shift under the student.
     *
     * @param  Collection<int, QuizQuestion>  $questions
     */
    private function freezeQuestionsFor(QuizAttempt $attempt, Collection $questions): void
    {
        $now = now();

        QuizAttemptQuestion::insert(
            $questions->values()
                ->map(fn (QuizQuestion $question, int $index): array => [
                    'quiz_attempt_id' => $attempt->id,
                    'quiz_question_id' => $question->id,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all()
        );
    }

    /**
     * Grade an in-progress attempt. Scores are always computed on the backend.
     *
     * @param  array{questions: array<int, array{question_id: int, answer: mixed}>}  $submission
     */
    public function submit(QuizAttempt $attempt, array $submission): QuizAttempt
    {
        // Grading is a read-then-write over the attempt, its answers, the grade
        // ledger and lesson progress. Locking the attempt row makes the
        // `submitted_at` guard atomic, so a double submit cannot write a second
        // set of answers, re-notify the student, or complete the lesson twice.
        return DB::transaction(function () use ($attempt, $submission): QuizAttempt {
            $attempt = QuizAttempt::whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // `submitted_at`, not the status: an attempt that ran out of time is
            // still graded, so it is `expired` rather than `completed` and a
            // status check would wave a second submit straight through.
            if ($attempt->isGraded()) {
                throw ValidationException::withMessages([
                    'attempt' => ['This attempt has already been submitted.'],
                ]);
            }

            return $this->gradeAttempt($attempt, $submission['questions'] ?? []);
        });
    }

    /**
     * Close an attempt whose clock ran out while nobody was watching, and score
     * whatever the student had saved.
     *
     * Nothing sweeps these rows, so this runs wherever an expired attempt is
     * noticed: starting another one, or a save that lands too late. Grading from
     * the drafts means a student who closed the tab at 29:58 keeps the work, and
     * the attempt lands in the gradebook rather than silently never existing.
     */
    private function closeExpired(QuizAttempt $attempt): QuizAttempt
    {
        // No payload: whatever was autosaved is the whole submission.
        return $this->gradeAttempt($attempt, []);
    }

    /**
     * Grade the attempt and settle everything that hangs off the result.
     *
     * Shared by the final submit and by {@see closeExpired()} so that a timed-out
     * attempt is recorded, notified and ledgered exactly like a submitted one.
     * The only difference is the status it lands on, which is what tells a reader
     * the student did not get to finish.
     *
     * @param  array<int, array{question_id: int, answer: mixed}>  $submitted
     */
    private function gradeAttempt(QuizAttempt $attempt, array $submitted): QuizAttempt
    {
        $quiz = $attempt->quiz;
        // `hasExpired` reads the hard cutoff, i.e. the end of any late grace:
        // an attempt handed in inside the grace is not a failure, it is late.
        $expired = $this->hasExpired($attempt, $quiz);

        // Late means past the strict deadline but still inside the grace that
        // kept it from being force-closed. The flag is what lets a reader tell
        // "on time" from "handed in after the bell".
        $strictDeadline = $quiz->deadlineFor($attempt);
        $late = ! $expired && $strictDeadline !== null && $strictDeadline->isPast();

        // The frozen set, not `$quiz->questions`: a bank quiz owns no
        // questions at all, and a fixed quiz's list can be edited while a
        // student is mid-attempt. Grading the live list would score against
        // a paper the student was never shown.
        $questions = $attempt->questions()->with('options')->get();

        $answersByQuestion = collect($submitted)
            ->keyBy(fn (array $item): int => (int) ($item['question_id'] ?? 0));

        // Anything the client did not send falls back to the last autosave.
        // Autosave is debounced, so the most recent keystroke is normally in
        // flight when the student hits Submit; grading from the payload
        // alone would score against the copy from a second ago.
        $drafts = $attempt->answers()->get()->keyBy('quiz_question_id');

        $totalEarned = 0.0;
        $totalPossible = 0.0;

        foreach ($questions as $question) {
            $answer = $this->effectiveAnswer($question, $answersByQuestion, $drafts);
            $earned = $this->gradeQuestion($question, $answer);

            // Negative marking: a wrong answer that was actually attempted (not
            // left blank) deducts a fraction of the question's points. A blank
            // is not punished -- a student who ran out of time on an untimed
            // question should not be double-charged for it.
            if ($earned === 0.0) {
                $earned = $this->negativeMarkingEarned($question, $answer);
            }

            $totalEarned += $earned;
            $totalPossible += (float) $question->points;

            QuizAnswer::updateOrCreate(
                ['quiz_attempt_id' => $attempt->id, 'quiz_question_id' => $question->id],
                [
                    'answer' => $this->serializeAnswer($question, $answer),
                    'is_correct' => $earned > 0,
                    'points_earned' => $earned,
                ],
            );
        }

        // Deductions cannot take the whole attempt below zero. Per-question
        // points_earned may still be negative, so a question can display its
        // own penalty in the review while the headline score stays sane.
        $totalEarned = max(0.0, $totalEarned);

        $percentage = $totalPossible > 0 ? round(($totalEarned / $totalPossible) * 100, 2) : 0.0;
        $passed = $percentage >= (float) $quiz->passing_score;

        $attempt->update([
            'status' => ($expired ? QuizAttemptStatus::Expired : QuizAttemptStatus::Completed)->value,
            'submitted_at' => now(),
            'score' => round($totalEarned, 2),
            'score_percentage' => $percentage,
            'passed' => $passed,
            'submitted_late' => $late,
        ]);

        // Record in the unified grade ledger (unique per source).
        Grade::updateOrCreate(
            ['source_type' => QuizAttempt::class, 'source_id' => $attempt->id],
            [
                'student_id' => $attempt->student_id,
                'course_id' => $quiz->course_id,
                'type' => 'quiz',
                'score' => round($totalEarned, 2),
                'max_score' => round($totalPossible, 2),
                'percentage' => $percentage,
                'graded_at' => now(),
            ],
        );

        $student = $attempt->student;
        $student->notify(new QuizResult($attempt));

        if ($passed) {
            $lesson = Lesson::where('quiz_id', $quiz->id)->first();
            if ($lesson) {
                $this->progress->completeLesson($lesson, $student);
            }
        }

        return $attempt->refresh()->load(['answers', 'questions.options']);
    }

    public function hasExpired(QuizAttempt $attempt, ?Quiz $quiz = null): bool
    {
        $quiz ??= $attempt->quiz;

        return $quiz->answerableUntil($attempt)?->isPast() ?? false;
    }

    /**
     * The answer to grade this question from: the submitted value if the client
     * sent one, otherwise the last autosaved draft, otherwise nothing.
     *
     * Presence in the payload is what decides, not truthiness, so a student who
     * deliberately cleared a question submits a blank rather than being handed
     * back the draft they just erased.
     *
     * @param  SupportCollection<int, array{answer: mixed}>  $submitted
     * @param  SupportCollection<int, QuizAnswer>  $drafts
     */
    private function effectiveAnswer(QuizQuestion $question, SupportCollection $submitted, SupportCollection $drafts): mixed
    {
        if ($submitted->has($question->id)) {
            return $submitted->get($question->id)['answer'] ?? null;
        }

        $draft = $drafts->get($question->id);

        if (! $draft) {
            return null;
        }

        return $this->graders->for($question->type)->decode($draft->answer);
    }

    public function graderFor(QuizQuestionType $type): Grader
    {
        return $this->graders->for($type);
    }

    private function gradeQuestion(QuizQuestion $question, mixed $submitted): float
    {
        return $this->graders->for($question->type)->grade($question, $submitted);
    }

    /**
     * The penalty, if any, for a wrong answer on a question with negative
     * marking. The fraction lives in `settings.negative_marking` and is a
     * fraction of the question's points: 0.25 on a 2-point question deducts 0.5.
     * Only a wrong answer that was actually attempted is penalised.
     *
     * @return float 0 when there is no penalty or the question was left blank
     */
    private function negativeMarkingEarned(QuizQuestion $question, mixed $submitted): float
    {
        $fraction = (float) ($question->settings['negative_marking'] ?? 0.0);

        if ($fraction <= 0.0 || $this->isBlankAnswer($question, $submitted)) {
            return 0.0;
        }

        return -round((float) $question->points * min(1.0, $fraction), 2);
    }

    /**
     * Whether a submission is empty for the purposes of negative marking.
     *
     * Checked through the grader's own round-trip so every type decides the
     * same way it decides later, when it serialises the answer for storage: a
     * fill-in-the-blank with every blank empty is blank, a partially filled one
     * is an attempt.
     */
    private function isBlankAnswer(QuizQuestion $question, mixed $submitted): bool
    {
        $decoded = $this->graders->for($question->type)->decode($this->serializeAnswer($question, $submitted));

        if (is_array($decoded)) {
            return collect($decoded)->flatten()->every(fn ($value): bool => $value === null || $value === '' || $value === []);
        }

        return $decoded === null || $decoded === '';
    }

    private function serializeAnswer(QuizQuestion $question, mixed $submitted): string
    {
        return $this->graders->for($question->type)->serialize($submitted);
    }
}
