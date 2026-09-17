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
            ],
        ], 201);
    }

    public function update(UpdateQuizRequest $request, Course $course, Quiz $quiz)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $quiz->course_id === (int) $course->id, 404);

        $quiz->update([
            ...$request->safe()->except(['time_limit_minutes']),
            'time_limit_minutes' => $request->filled('time_limit_minutes') ? (int) $request->input('time_limit_minutes') : null,
        ]);

        return response()->json([
            'message' => 'Quiz updated.',
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'instructions' => $quiz->instructions,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'passing_score' => (float) $quiz->passing_score,
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

        $quiz->load(['lesson:id,title,section_id', 'questions.options']);

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'instructions' => $quiz->instructions,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'passing_score' => (float) $quiz->passing_score,
                'course_id' => $quiz->course_id,
                'lesson_id' => $quiz->lesson?->id,
                'lesson_title' => $quiz->lesson?->title,
                'questions' => $quiz->questions->map(fn (QuizQuestion $question): array => [
                    'id' => $question->id,
                    'type' => $question->type->value,
                    'question_text' => $question->question_text,
                    'points' => (float) $question->points,
                    'explanation' => $question->explanation,
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

        $question->update([
            'type' => $request->input('type'),
            'question_text' => $request->input('question_text'),
            'points' => $request->input('points'),
            'explanation' => $request->input('explanation'),
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

        $question->options()->delete();
        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    private function createQuestion(Quiz $quiz, StoreQuizQuestionRequest $request): QuizQuestion
    {
        $question = $quiz->questions()->create([
            'type' => $request->input('type'),
            'question_text' => $request->input('question_text'),
            'points' => $request->input('points'),
            'explanation' => $request->input('explanation'),
        ]);

        $this->syncOptions($question, $request->input('options', []), $question->type);

        return $question;
    }

    private function syncOptions(QuizQuestion $question, array $options, $type): void
    {
        foreach ($options as $option) {
            if ($type === QuizQuestionType::TrueFalse) {
                $optionText = $option['option_text'] ?? '';
                $isCorrect = ($option['is_correct'] ?? false) ? true : false;

                $question->options()->create([
                    'option_text' => $optionText,
                    'is_correct' => $isCorrect,
                    'explanation' => $option['explanation'] ?? null,
                ]);
            } else {
                $question->options()->create([
                    'option_text' => $option['option_text'],
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'explanation' => $option['explanation'] ?? null,
                ]);
            }
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
            'options' => $question->options->map(fn (QuizOption $option): array => [
                'id' => $option->id,
                'option_text' => $option->option_text,
                'is_correct' => (bool) $option->is_correct,
                'explanation' => $option->explanation,
            ]),
        ];
    }
}
