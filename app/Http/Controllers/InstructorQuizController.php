<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quiz\StoreQuizQuestionRequest;
use App\Http\Requests\Quiz\StoreQuizRequest;
use App\Http\Requests\Quiz\UpdateQuizRequest;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizBlueprintRule;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Services\QuestionEditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $quiz = DB::transaction(function () use ($course, $request): Quiz {
            $quiz = $course->quizzes()->create([
                ...$request->safe()->except(['blueprint', 'late_grace_minutes']),
                'time_limit_minutes' => $request->filled('time_limit_minutes') ? (int) $request->input('time_limit_minutes') : null,
                'late_grace_minutes' => $request->filled('late_grace_minutes') ? (int) $request->input('late_grace_minutes') : null,
            ]);

            $this->syncBlueprint($quiz, $request->input('blueprint'));

            return $quiz;
        });

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
                'late_grace_minutes' => $quiz->late_grace_minutes,
                'blueprint' => $this->blueprintPayload($quiz),
            ],
        ], 201);
    }

    public function update(UpdateQuizRequest $request, Course $course, Quiz $quiz)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $quiz->course_id === (int) $course->id, 404);

        // The quiz row and its blueprint have to move together: a half-applied
        // save would leave quotas on a quiz whose bank was detached, or drop the
        // quotas from a quiz that is still drawing from one.
        DB::transaction(function () use ($request, $quiz): void {
            $quiz->update([
                ...$request->safe()->except(['time_limit_minutes', 'question_bank_id', 'draw_size', 'blueprint', 'late_grace_minutes']),
                'time_limit_minutes' => $request->filled('time_limit_minutes') ? (int) $request->input('time_limit_minutes') : null,
                'late_grace_minutes' => $request->filled('late_grace_minutes') ? (int) $request->input('late_grace_minutes') : null,
                // These form requests describe the whole quiz (`title`,
                // `passing_score` and `attempts_allowed` are all required), so an
                // absent bank means "go back to owning its own questions" rather
                // than "leave the bank alone". Spreading the validated payload
                // alone would make a bank impossible to detach.
                'question_bank_id' => $request->input('question_bank_id') === null
                    ? null
                    : (int) $request->input('question_bank_id'),
                'draw_size' => $request->input('draw_size') === null
                    ? null
                    : (int) $request->input('draw_size'),
            ]);

            // Sent unconditionally, so detaching a bank also drops the quotas
            // that only meant something for that bank's draw.
            $this->syncBlueprint($quiz, $request->input('blueprint'));
        });

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
                'available_from' => $quiz->available_from?->format('Y-m-d\TH:i'),
                'available_until' => $quiz->available_until?->format('Y-m-d\TH:i'),
                'late_grace_minutes' => $quiz->late_grace_minutes,
                'blueprint' => $this->blueprintPayload($quiz->fresh()),
            ],
        ]);
    }

    /**
     * Replace the quiz's quotas with the submitted set.
     *
     * Replace rather than diff, because the rules are a set the author edits as a
     * whole: a quota dropped from the form has to disappear, and there is no
     * meaningful "last updated" per rule to reconcile.
     *
     * @param  mixed  $blueprint
     */
    private function syncBlueprint(Quiz $quiz, $blueprint): void
    {
        $quiz->blueprintRules()->delete();

        foreach ((array) $blueprint as $rule) {
            if (! is_array($rule) || ! isset($rule['type'], $rule['count'])) {
                continue;
            }

            $count = (int) $rule['count'];

            if ($count < 1) {
                continue;
            }

            $quiz->blueprintRules()->create([
                'question_type' => (string) $rule['type'],
                'question_count' => $count,
            ]);
        }
    }

    /**
     * @return list<array{type: string, count: int}>
     */
    private function blueprintPayload(Quiz $quiz): array
    {
        return $quiz->blueprintRules()
            ->orderBy('question_type')
            ->get()
            ->map(fn (QuizBlueprintRule $rule): array => [
                'type' => $rule->question_type->value,
                'count' => (int) $rule->question_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Full quiz breakdown (questions + options incl. correct flags) for the builder.
     */
    public function show(Request $request, Course $course, Quiz $quiz)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $quiz->course_id === (int) $course->id, 404);

        $quiz->load([
            'lesson:id,title,section_id',
            'questions' => fn ($query) => $query->with('options')->withExists('attemptSnapshots'),
            'questionBank:id,title',
        ]);

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
                'available_from' => $quiz->available_from?->format('Y-m-d\TH:i'),
                'available_until' => $quiz->available_until?->format('Y-m-d\TH:i'),
                'late_grace_minutes' => $quiz->late_grace_minutes,
                'question_bank' => $quiz->questionBank ? [
                    'id' => $quiz->questionBank->id,
                    'title' => $quiz->questionBank->title,
                ] : null,
                'blueprint' => $this->blueprintPayload($quiz),
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
                    'version' => (int) $question->version,
                    'in_use' => $question->isInUse(),
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

    public function storeQuestion(QuestionEditor $editor, StoreQuizQuestionRequest $request, Course $course, Quiz $quiz)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $quiz->course_id === (int) $course->id, 404);

        $question = $editor->create($quiz, $request);

        return response()->json([
            'message' => 'Question added.',
            'question' => $this->questionPayload($question->load('options')),
        ], 201);
    }

    public function updateQuestion(QuestionEditor $editor, StoreQuizQuestionRequest $request, Course $course, Quiz $quiz, QuizQuestion $question)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $question->quiz_id === (int) $quiz->id, 404);

        $saved = $editor->update($question, $request);
        $forked = $saved->isNot($question);

        return response()->json([
            'message' => $forked
                ? "A new version (v{$saved->version}) of this question was created. Students who already attempted it keep the earlier version."
                : 'Question updated.',
            'question' => $this->questionPayload($saved->load('options')),
            'forked' => $forked,
        ]);
    }

    public function destroyQuestion(QuestionEditor $editor, Request $request, Course $course, Quiz $quiz, QuizQuestion $question)
    {
        $this->authorize('manage', $course);
        abort_unless((int) $question->quiz_id === (int) $quiz->id, 404);
        $editor->assertDeletable($question);

        $question->options()->delete();
        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
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
            'version' => (int) $question->version,
            'in_use' => $question->isInUse(),
            'options' => $question->options->map(fn (QuizOption $option): array => [
                'id' => $option->id,
                'option_text' => $option->option_text,
                'is_correct' => (bool) $option->is_correct,
                'explanation' => $option->explanation,
            ]),
        ];
    }
}
