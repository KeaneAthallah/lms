<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Notifications\QuizResult;
use App\QuizAttemptStatus;
use App\QuizQuestionType;
use Illuminate\Validation\ValidationException;

class QuizService
{
    public function __construct(protected ProgressService $progress) {}

    public function canStart(Quiz $quiz, User $student): bool
    {
        if ((int) $quiz->attempts_allowed === 0) {
            return true;
        }

        return $quiz->attemptsFor($student) < (int) $quiz->attempts_allowed;
    }

    public function start(Quiz $quiz, User $student): QuizAttempt
    {
        if (! $this->canStart($quiz, $student)) {
            throw ValidationException::withMessages([
                'attempts' => ['You have used all of your allowed attempts for this quiz.'],
            ]);
        }

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'status' => QuizAttemptStatus::InProgress,
            'started_at' => now(),
        ]);

        $this->progress->markStarted($quiz->lesson, $student);

        return $attempt;
    }

    /**
     * Grade an in-progress attempt. Scores are always computed on the backend.
     *
     * @param  array{questions: array<int, array{question_id: int, answer: mixed}>}  $submission
     */
    public function submit(QuizAttempt $attempt, array $submission): QuizAttempt
    {
        $quiz = $attempt->quiz;
        $questions = $quiz->questions()->with('options')->get();

        if ($attempt->isCompleted()) {
            throw ValidationException::withMessages([
                'attempt' => ['This attempt has already been submitted.'],
            ]);
        }

        if ($this->hasExpired($attempt, $quiz)) {
            $attempt->update(['status' => QuizAttemptStatus::Expired->value]);
            $attempt->refresh();

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

        return $attempt->refresh()->load(['answers', 'quiz.questions.options']);
    }

    public function hasExpired(QuizAttempt $attempt, ?Quiz $quiz = null): bool
    {
        $quiz ??= $attempt->quiz;

        if (! $quiz->time_limit_minutes) {
            return false;
        }

        return $attempt->started_at->addMinutes((int) $quiz->time_limit_minutes)->isPast();
    }

    private function gradeQuestion(QuizQuestion $question, mixed $submitted): float
    {
        if ($submitted === null || $submitted === '') {
            return 0.0;
        }

        return match ($question->type) {
            QuizQuestionType::ShortAnswer => $this->gradeShortAnswer($question, $submitted),
            default => $this->gradeChoice($question, $submitted),
        };
    }

    private function gradeChoice(QuizQuestion $question, mixed $submitted): float
    {
        $correct = $question->options->firstWhere('is_correct', true);

        if (! $correct) {
            return 0.0;
        }

        $chosen = (int) $submitted;

        return $correct->id === $chosen ? (float) $question->points : 0.0;
    }

    private function gradeShortAnswer(QuizQuestion $question, mixed $submitted): float
    {
        $correct = $question->options->firstWhere('is_correct', true);

        if (! $correct) {
            return 0.0;
        }

        $normalize = fn (string $value): string => strtolower(trim((string) $value));

        return $normalize((string) $submitted) === $normalize($correct->option_text)
            ? (float) $question->points
            : 0.0;
    }

    private function serializeAnswer(QuizQuestion $question, mixed $submitted): string
    {
        if ($question->type === QuizQuestionType::ShortAnswer) {
            return (string) $submitted;
        }

        return (string) ((int) $submitted);
    }
}
