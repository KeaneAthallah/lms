<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quiz\StoreQuizQuestionRequest;
use App\Http\Requests\Quiz\StoreQuizRequest;
use App\Http\Requests\Quiz\UpdateQuizRequest;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\QuizQuestionType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InstructorQuizController extends Controller
{
    public function store(StoreQuizRequest $request, Course $course, Lesson $lesson)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $lesson->course_id === (int) $course->id, 404);

        if ($lesson->quiz) {
            throw ValidationException::withMessages([
                'quiz' => ['This lesson already has a quiz attached.'],
            ]);
        }

        $quiz = $course->quizzes()->create([
            ...$request->safe(),
            'time_limit_minutes' => $request->filled('time_limit_minutes') ? (int) $request->input('time_limit_minutes') : null,
        ]);

        $lesson->update(['type' => 'quiz', 'quiz_id' => $quiz->id]);

        return response()->json([
            'message' => 'Quiz created. Add questions to complete it.',
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'instructions' => $quiz->instructions,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'passing_score' => (float) $quiz->passing_score,
                'questions_count' => 0,
                'lesson_id' => $lesson->id,
                'question_bank_id' => $quiz->question_bank_id,
                'draw_size' => $quiz->draw_size,
            ],
        ], 201);
    }

    public function update(UpdateQuizRequest $request, Course $course, Quiz $quiz)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $quiz->course_id === (int) $course->id, 404);

        $quiz->update([
            ...$request->safe()->except(['time_limit_minutes', 'question_bank_id', 'draw_size']),
            'time_limit_minutes' => $request->filled('time_limit_minutes') ? (int) $request->input('time_limit_minutes') : null,
            // These form requests describe the whole quiz (`title`,
            // `passing_score` and `attempts_allowed` are all required), so an
            // absent bank means "go back to owning its own questions" rather
            // than "leave the bank alone". Spreading the validated payload alone
            // would make a bank impossible to detach.
            'question_bank_id' => $request->input('question_bank_id') === null
                ? null
                : (int) $request->input('question_bank_id'),
            'draw_size' => $request->input('draw_size') === null
                ? null
                : (int) $request->input('draw_size'),
        ]);

        return response()->json([
            'message' => 'Quiz updated.',
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'instructions' => $quiz->instructions,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'passing_score' => (float) $quiz->passing_score,
                'question_bank_id' => $quiz->question_bank_id,
                'draw_size' => $quiz->draw_size,
            ],
        ]);
    }

    /**
     * Full quiz breakdown (questions + options incl. correct flags) for the builder.
     */
    public function show(Request $request, Course $course, Quiz $quiz)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $quiz->course_id === (int) $course->id, 404);

        $quiz->load(['lesson:id,title,section_id', 'questions.options', 'questionBank:id,title']);

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'instructions' => $quiz->instructions,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'passing_score' => (float) $quiz->passing_score,
                'course_id' => $quiz->course_id,
                'question_bank_id' => $quiz->question_bank_id,
                'draw_size' => $quiz->draw_size,
                'question_bank' => $quiz->questionBank ? [
                    'id' => $quiz->questionBank->id,
                    'title' => $quiz->questionBank->title,
                ] : null,
                'lesson_id' => $quiz->lesson?->id,
                'lesson_title' => $quiz->lesson?->title,
                'questions' => $quiz->questions->map(fn (QuizQuestion $question): array => [
                    'id' => $question->id,
                    'type' => $question->type->value,
                    'question_text' => $question->question_text,
                    'points' => (float) $question->points,
                    'explanation' => $question->explanation,
                    'sort_order' => (int) $question->sort_order,
                    'settings' => $question->settings ?? [],
                    'options' => $question->options->map(fn (QuizOption $option): array => [
                        'id' => $option->id,
                        'option_text' => $option->option_text,
                        'is_correct' => (bool) $option->is_correct,
                        'explanation' => $option->explanation,
                    ]),
                ]),
            ],
        ]);
    }

    public function destroy(Request $request, Course $course, Quiz $quiz)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $quiz->course_id === (int) $course->id, 404);

        if ($quiz->lesson) {
            $quiz->lesson->update(['type' => 'text', 'quiz_id' => null]);
        }

        $quiz->questions()->delete();
        $quiz->delete();

        return response()->json(['message' => 'Quiz deleted.']);
    }

    public function storeQuestion(StoreQuizQuestionRequest $request, Course $course, Quiz $quiz)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $quiz->course_id === (int) $course->id, 404);

        $question = $this->createQuestion($quiz, $request);

        return response()->json([
            'message' => 'Question added.',
            'question' => $this->questionPayload($question->load('options')),
        ], 201);
    }

    public function updateQuestion(StoreQuizQuestionRequest $request, Course $course, Quiz $quiz, QuizQuestion $question)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $question->quiz_id === (int) $quiz->id, 404);
        $this->guardUnusedQuestion($question);

        $question->update([
            'type' => $request->input('type'),
            'question_text' => $request->input('question_text'),
            'points' => $request->input('points'),
            'explanation' => $request->input('explanation'),
            'settings' => $this->settingsFor($request),
            'sort_order' => $request->input('sort_order') ?? $question->sort_order,
        ]);

        if ($request->has('options')) {
            $question->options()->delete();
            $this->syncOptions($question, $request->input('options', []), $question->type);
        }

        return response()->json([
            'message' => 'Question updated.',
            'question' => $this->questionPayload($question->fresh()->load('options')),
        ]);
    }

    public function destroyQuestion(Request $request, Course $course, Quiz $quiz, QuizQuestion $question)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $question->quiz_id === (int) $quiz->id, 404);
        $this->guardUnusedQuestion($question);

        $question->options()->delete();
        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    /**
     * Refuse to change a question a student has already been served.
     *
     * An attempt records the questions it was given and its review page is
     * rebuilt from that record, so rewriting the question would make a completed
     * attempt claim the student was asked something they never saw. Adding a new
     * question is how to iterate.
     *
     * @throws ValidationException
     */
    private function guardUnusedQuestion(QuizQuestion $question): void
    {
        if (! $question->isInUse()) {
            return;
        }

        throw ValidationException::withMessages([
            'question' => ['This question has already been used in a student attempt, so it cannot be changed or removed. Add a new question instead.'],
        ]);
    }

    private function createQuestion(Quiz $quiz, StoreQuizQuestionRequest $request): QuizQuestion
    {
        $question = $quiz->questions()->create([
            'type' => $request->input('type'),
            'question_text' => $request->input('question_text'),
            'explanation' => $request->input('explanation'),
            'points' => $request->input('points'),
            'settings' => $this->settingsFor($request),
            // Appended questions used to all land on the column default of 0, so
            // the quiz order was whatever the database happened to return.
            'sort_order' => $request->input('sort_order')
                ?? ((int) $quiz->questions()->max('sort_order') + 1),
        ]);

        $this->syncOptions($question, $request->input('options', []), $question->type);

        return $question;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settingsFor(StoreQuizQuestionRequest $request): ?array
    {
        $settings = $request->input('settings');

        return is_array($settings) && $settings !== [] ? $settings : null;
    }

    /**
     * Replace the question's options with the submitted set.
     *
     * Correct flags are meaningless for the types that keep their answer key in
     * `settings`, so they are dropped rather than stored misleadingly.
     */
    private function syncOptions(QuizQuestion $question, array $options, QuizQuestionType $type): void
    {
        $usesOptionKey = in_array($type, [
            QuizQuestionType::MultipleChoice,
            QuizQuestionType::TrueFalse,
            QuizQuestionType::MultiSelect,
        ], true);

        foreach ($options as $option) {
            $question->options()->create([
                'option_text' => $option['option_text'],
                'is_correct' => $usesOptionKey && (bool) ($option['is_correct'] ?? false),
                'explanation' => $option['explanation'] ?? null,
            ]);
        }
    }

    private function questionPayload(QuizQuestion $question): array
    {
        return [
            'id' => $question->id,
            'type' => $question->type->value,
            'question_text' => $question->question_text,
            'points' => (float) $question->points,
            'explanation' => $question->explanation,
            'sort_order' => (int) $question->sort_order,
            'settings' => $question->settings ?? [],
            'options' => $question->options->map(fn (QuizOption $option): array => [
                'id' => $option->id,
                'option_text' => $option->option_text,
                'is_correct' => (bool) $option->is_correct,
                'explanation' => $option->explanation,
            ]),
        ];
    }
}
