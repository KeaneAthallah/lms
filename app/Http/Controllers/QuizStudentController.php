<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quiz\SubmitQuizRequest;
use App\Http\Resources\QuizResource;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Services\QuizService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QuizStudentController extends Controller
{
    public function __construct(protected QuizService $quizzes) {}

    public function show(Request $request, Quiz $quiz)
    {
        $this->authorize('view', $quiz);

        $quiz->load(['course:id,title,slug', 'lesson:id,title,type']);
        $quiz->loadCount('questions');

        if ($request->user()) {
            $quiz->load(['attempts' => fn ($q) => $q->where('student_id', $request->user()->id)->orderByDesc('id')->take(10)]);
        }

        return new QuizResource($quiz);
    }

    public function start(Request $request, Quiz $quiz)
    {
        $user = $request->user();
        $this->authorize('take', $quiz);

        $attempt = $this->quizzes->start($quiz, $user);

        $attempt->load(['quiz', 'quiz.questions.options']);

        return response()->json([
            'attempt' => $attempt,
            'questions' => $attempt->quiz->questions->map(fn (QuizQuestion $question): array => [
                'id' => $question->id,
                'type' => $question->type->value,
                'question_text' => $question->question_text,
                'points' => (float) $question->points,
                'options' => $question->options->map(fn ($option): array => [
                    'id' => $option->id,
                    'option_text' => $option->option_text,
                ]),
            ]),
            'time_limit_minutes' => $attempt->quiz->time_limit_minutes,
            'expires_at' => $attempt->quiz->time_limit_minutes
                ? $attempt->started_at->addMinutes((int) $attempt->quiz->time_limit_minutes)->toISOString()
                : null,
        ], 201);
    }

    public function submit(SubmitQuizRequest $request, QuizAttempt $attempt)
    {
        $this->authorize('submit', $attempt);

        $this->quizzes->submit($attempt, $request->validated());

        return response()->json($this->resultPayload($attempt->fresh()->load(['quiz.questions.options', 'answers'])));
    }

    public function showAttempt(Request $request, QuizAttempt $attempt)
    {
        $this->authorize('view', $attempt);

        if ($attempt->status->value === 'in_progress' && ! $this->quizzes->hasExpired($attempt)) {
            throw ValidationException::withMessages([
                'attempt' => ['This attempt is still in progress.'],
            ]);
        }

        return response()->json($this->resultPayload($attempt->load(['quiz.questions.options', 'answers'])));
    }

    /**
     * Build the full result report for a completed attempt.
     */
    private function resultPayload(QuizAttempt $attempt): array
    {
        $quiz = $attempt->quiz;
        $answers = $attempt->answers->keyBy('quiz_question_id');

        $questions = $quiz->questions->map(function (QuizQuestion $question) use ($answers): array {
            $answer = $answers->get($question->id);
            $chosenId = $question->type->value === 'short_answer' ? null : (int) $answer?->answer;

            return [
                'id' => $question->id,
                'type' => $question->type->value,
                'question_text' => $question->question_text,
                'points' => (float) $question->points,
                'is_correct' => $answer?->is_correct,
                'points_earned' => (float) ($answer?->points_earned ?? 0),
                'submitted_answer' => $question->type->value === 'short_answer' ? $answer?->answer : $chosenId,
                'options' => $question->options->map(fn ($option): array => [
                    'id' => $option->id,
                    'option_text' => $option->option_text,
                    'is_correct' => $option->is_correct,
                    'explanation' => $option->explanation,
                    'chosen' => $chosenId !== null && (int) $option->id === $chosenId,
                ]),
            ];
        });

        return [
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status->value,
                'score' => $attempt->score !== null ? (float) $attempt->score : null,
                'score_percentage' => $attempt->score_percentage !== null ? (float) $attempt->score_percentage : null,
                'passed' => $attempt->passed,
                'started_at' => $attempt->started_at?->toISOString(),
                'submitted_at' => $attempt->submitted_at?->toISOString(),
                'quiz' => [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'passing_score' => (float) $quiz->passing_score,
                    'total_points' => $quiz->questions->sum('points'),
                    'course_slug' => $quiz->course->slug ?? null,
                ],
            ],
            'questions' => $questions,
        ];
    }
}
