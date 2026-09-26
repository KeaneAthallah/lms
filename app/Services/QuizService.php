<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Notifications\QuizResult;
use App\QuizAttemptStatus;
use App\QuizQuestionType;
use App\Support\Grading\Grader;
use App\Support\Grading\GraderRegistry;
use Illuminate\Database\Eloquent\Collection;
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

    public function start(Quiz $quiz, User $student): QuizAttempt
    {
        // The attempt limit is a check-then-insert, so the quiz row is locked for
        // the duration. Without the lock two clicks (or two tabs) both read
        // "one attempt used" and both insert, exceeding `attempts_allowed`.
        return DB::transaction(function () use ($quiz, $student): QuizAttempt {
            Quiz::whereKey($quiz->id)->lockForUpdate()->first();

            if (! $this->canStart($quiz, $student)) {
                throw ValidationException::withMessages([
                    'attempts' => ['You have used all of your allowed attempts for this quiz.'],
                ]);
            }

            $questions = $this->resolveQuestions($quiz);

            if ($questions->isEmpty()) {
                throw ValidationException::withMessages([
                    'quiz' => [$quiz->drawsFromBank()
                        ? 'This quiz has no questions available yet.'
                        : 'This quiz has no questions yet.'],
                ]);
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

            return $attempt;
        });
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

        return $bank->questions()
            ->with('options')
            // `reorder()` drops the relation's default `sort_order`, which would
            // otherwise win: SQL only honours the first ORDER BY, so adding
            // `inRandomOrder()` on top of it looks random in the code and serves
            // the same first N questions to every student.
            ->reorder()
            ->inRandomOrder()
            ->limit(max(1, (int) ($quiz->draw_size ?? 0)))
            ->get()
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
        // `isCompleted()` guard atomic, so a double submit cannot write a second
        // set of answers, re-notify the student, or complete the lesson twice.
        return DB::transaction(function () use ($attempt, $submission): QuizAttempt {
            $attempt = QuizAttempt::whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $quiz = $attempt->quiz;

            // The frozen set, not `$quiz->questions`: a bank quiz owns no
            // questions at all, and a fixed quiz's list can be edited while a
            // student is mid-attempt. Grading the live list would score against
            // a paper the student was never shown.
            $questions = $attempt->questions()->with('options')->get();

            if ($attempt->isCompleted()) {
                throw ValidationException::withMessages([
                    'attempt' => ['This attempt has already been submitted.'],
                ]);
            }

            if ($this->hasExpired($attempt, $quiz)) {
                $attempt->update(['status' => QuizAttemptStatus::Expired->value]);

                throw ValidationException::withMessages([
                    'attempt' => ['The time limit for this quiz has expired.'],
                ]);
            }

            $answersByQuestion = collect($submission['questions'] ?? [])
                ->keyBy(fn (array $item): int => (int) ($item['question_id'] ?? 0));

            $totalEarned = 0.0;
            $totalPossible = 0.0;

            foreach ($questions as $question) {
                $submitted = $answersByQuestion->get($question->id)['answer'] ?? null;
                $earned = $this->gradeQuestion($question, $submitted);

                $totalEarned += $earned;
                $totalPossible += (float) $question->points;

                QuizAnswer::updateOrCreate(
                    ['quiz_attempt_id' => $attempt->id, 'quiz_question_id' => $question->id],
                    [
                        'answer' => $this->serializeAnswer($question, $submitted),
                        'is_correct' => $earned > 0,
                        'points_earned' => $earned,
                    ],
                );
            }

            $percentage = $totalPossible > 0 ? round(($totalEarned / $totalPossible) * 100, 2) : 0.0;
            $passed = $percentage >= (float) $quiz->passing_score;

            $attempt->update([
                'status' => QuizAttemptStatus::Completed->value,
                'submitted_at' => now(),
                'score' => round($totalEarned, 2),
                'score_percentage' => $percentage,
                'passed' => $passed,
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
        });
    }

    public function hasExpired(QuizAttempt $attempt, ?Quiz $quiz = null): bool
    {
        $quiz ??= $attempt->quiz;

        if (! $quiz->time_limit_minutes) {
            return false;
        }

        return $attempt->started_at->addMinutes((int) $quiz->time_limit_minutes)->isPast();
    }

    public function graderFor(QuizQuestionType $type): Grader
    {
        return $this->graders->for($type);
    }

    private function gradeQuestion(QuizQuestion $question, mixed $submitted): float
    {
        return $this->graders->for($question->type)->grade($question, $submitted);
    }

    private function serializeAnswer(QuizQuestion $question, mixed $submitted): string
    {
        return $this->graders->for($question->type)->serialize($submitted);
    }
}
