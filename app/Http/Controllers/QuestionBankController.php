<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quiz\StoreQuestionBankRequest;
use App\Http\Requests\Quiz\StoreQuizQuestionRequest;
use App\Models\Course;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\QuizQuestionType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Course-scoped question banks.
 *
 * A bank is a reusable pool that one or more quizzes draw a random sample from.
 * It is deliberately separate from `InstructorQuizController` even though the
 * authoring of a question looks the same: a bank question is owned by the bank
 * and shared by every quiz that draws from it, so editing and deleting it have
 * rules a quiz's own questions do not.
 */
class QuestionBankController extends Controller
{
    public function index(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        $banks = $course->questionBanks()
            ->withCount('questions')
            ->withSum('questions', 'points')
            ->withCount('quizzes')
            ->orderBy('title')
            ->get();

        return response()->json([
            'banks' => $banks->map(fn (QuestionBank $bank): array => $this->bankPayload($bank))->all(),
        ]);
    }

    public function store(StoreQuestionBankRequest $request, Course $course)
    {
        $this->authorize('manage', $course);

        $bank = $course->questionBanks()->create([
            ...$request->validated(),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Question bank created.',
            'bank' => $this->bankPayload($bank->loadCount('questions')),
        ], 201);
    }

    public function update(StoreQuestionBankRequest $request, Course $course, QuestionBank $questionBank)
    {
        $this->authorize('manage', $course);
        $this->abortUnlessInCourse($course, $questionBank);

        $questionBank->update($request->validated());

        return response()->json([
            'message' => 'Question bank updated.',
            'bank' => $this->bankPayload($questionBank->fresh()->loadCount('questions')),
        ]);
    }

    public function destroy(Request $request, Course $course, QuestionBank $questionBank)
    {
        $this->authorize('manage', $course);
        $this->abortUnlessInCourse($course, $questionBank);

        // Deleting a bank that a quiz draws from would leave that quiz
        // permanently unservable, and the schema refuses it with a raw
        // constraint error. Say so plainly instead.
        $quizzesUsing = $questionBank->quizzes()->count();

        if ($quizzesUsing > 0) {
            throw ValidationException::withMessages([
                'bank' => ["This bank is used by {$quizzesUsing} quiz(es). Point them elsewhere before deleting it."],
            ]);
        }

        $questionBank->questions()
            ->withExists('attemptSnapshots')
            ->get()
            ->each(function (QuizQuestion $question): void {
                $this->guardUnusedQuestion($question);
            });

        $questionBank->delete();

        return response()->json(['message' => 'Question bank deleted.']);
    }

    public function show(Request $request, Course $course, QuestionBank $questionBank)
    {
        $this->authorize('manage', $course);
        $this->abortUnlessInCourse($course, $questionBank);

        $questionBank->load([
            // `withExists` resolves `isInUse()` for every question in one query
            // instead of one per question.
            'questions' => fn ($query) => $query->with('options')->withExists('attemptSnapshots'),
            // The foreign key has to be in the column list: a column-subset
            // eager load matches rows on it, and leaving it out silently
            // hydrates an empty collection rather than raising.
            'quizzes' => fn ($query) => $query->select(['id', 'question_bank_id', 'title', 'draw_size']),
        ]);

        return response()->json([
            'bank' => [
                ...$this->bankPayload($questionBank->loadCount('questions')),
                'quizzes' => $questionBank->quizzes->map(fn (Quiz $quiz): array => [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'draw_size' => $quiz->draw_size,
                ])->all(),
                'questions' => $questionBank->questions->map(fn (QuizQuestion $question): array => [
                    'id' => $question->id,
                    'type' => $question->type->value,
                    'question_text' => $question->question_text,
                    'points' => (float) $question->points,
                    'explanation' => $question->explanation,
                    'sort_order' => (int) $question->sort_order,
                    'settings' => $question->settings ?? [],
                    // A bank question shared across quizzes may already be in
                    // student attempts, so the builder needs to know it is frozen
                    // before offering an edit control.
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

    public function storeQuestion(StoreQuizQuestionRequest $request, Course $course, QuestionBank $questionBank)
    {
        $this->authorize('manage', $course);
        $this->abortUnlessInCourse($course, $questionBank);

        $question = $this->createQuestion($questionBank, $request);

        return response()->json([
            'message' => 'Question added to the bank.',
            'question' => $this->questionPayload($question->load('options')),
        ], 201);
    }

    public function updateQuestion(StoreQuizQuestionRequest $request, Course $course, QuestionBank $questionBank, QuizQuestion $question)
    {
        $this->authorize('manage', $course);
        $this->abortUnlessInCourse($course, $questionBank);
        abort_unless((int) $question->question_bank_id === (int) $questionBank->id, 404);
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

    public function destroyQuestion(Request $request, Course $course, QuestionBank $questionBank, QuizQuestion $question)
    {
        $this->authorize('manage', $course);
        $this->abortUnlessInCourse($course, $questionBank);
        abort_unless((int) $question->question_bank_id === (int) $questionBank->id, 404);
        $this->guardUnusedQuestion($question);

        $question->options()->delete();
        $question->delete();

        return response()->json(['message' => 'Question removed from the bank.']);
    }

    /**
     * Refuse to change a question a student has already been served.
     *
     * Every attempt records the questions it was given, and a graded attempt's
     * review page is rebuilt from that record. Rewriting the question would make
     * a completed attempt claim the student was asked something they never saw.
     * Adding a new question is the way to iterate.
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

    private function abortUnlessInCourse(Course $course, QuestionBank $bank): void
    {
        abort_unless((int) $bank->course_id === (int) $course->id, 404);
    }

    private function createQuestion(QuestionBank $bank, StoreQuizQuestionRequest $request): QuizQuestion
    {
        $question = $bank->questions()->create([
            'type' => $request->input('type'),
            'question_text' => $request->input('question_text'),
            'explanation' => $request->input('explanation'),
            'points' => $request->input('points'),
            'settings' => $this->settingsFor($request),
            'sort_order' => $request->input('sort_order')
                ?? ((int) $bank->questions()->max('sort_order') + 1),
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
     * @param  array<int, array<string, mixed>>  $options
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
            'in_use' => $question->isInUse(),
            'options' => $question->options->map(fn (QuizOption $option): array => [
                'id' => $option->id,
                'option_text' => $option->option_text,
                'is_correct' => (bool) $option->is_correct,
                'explanation' => $option->explanation,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bankPayload(QuestionBank $bank): array
    {
        return [
            'id' => $bank->id,
            'course_id' => $bank->course_id,
            'title' => $bank->title,
            'description' => $bank->description,
            'questions_count' => (int) ($bank->questions_count ?? 0),
            'points_total' => (float) ($bank->questions_sum_points ?? 0),
            'quizzes_count' => (int) ($bank->quizzes_count ?? 0),
        ];
    }
}
