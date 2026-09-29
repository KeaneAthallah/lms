<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Question banks, and the per-attempt snapshot they depend on.
 *
 * Most of these assert that a student's paper cannot shift after they have been
 * served it. That is the property the whole design exists for: a bank is shared
 * and editable, so without a frozen record per attempt a bank edit would
 * retroactively rewrite a graded result, and a student reopening an attempt
 * could be shown a different set than the one they answered.
 */
class QuestionBankTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private Course $course;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create();
        $this->course = Course::factory()->for($this->instructor, 'instructor')->create();
        $this->student = User::factory()->student()->create();
    }

    private function baseUrl(string $suffix = ''): string
    {
        return "/api/instructor/courses/{$this->course->slug}{$suffix}";
    }

    /**
     * A bank holding $count multiple-choice questions, each with a known answer.
     */
    private function makeBank(int $count = 5, ?Course $course = null): QuestionBank
    {
        $bank = QuestionBank::factory()->create([
            'course_id' => ($course ?? $this->course)->id,
            'created_by' => $this->instructor->id,
        ]);

        foreach (range(1, $count) as $index) {
            $question = QuizQuestion::factory()->forBank($bank)->create([
                'sort_order' => $index,
                'question_text' => "Bank question {$index}?",
            ]);

            QuizOption::factory()->correct()->create(['quiz_question_id' => $question->id, 'sort_order' => 1]);
            QuizOption::factory()->create(['quiz_question_id' => $question->id, 'sort_order' => 2]);
        }

        return $bank->fresh();
    }

    private function makeBankQuiz(QuestionBank $bank, int $drawSize = 3): Quiz
    {
        return Quiz::factory()->for($this->course)->create([
            'question_bank_id' => $bank->id,
            'draw_size' => $drawSize,
        ]);
    }

    private function makeLesson(): Lesson
    {
        $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

        return Lesson::factory()->create([
            'course_id' => $this->course->id,
            'section_id' => $section->id,
            'type' => 'text',
        ]);
    }

    private function enrollStudent(): void
    {
        (new EnrollmentService)->enroll($this->student, $this->course);
    }

    // ---------------------------------------------------------------- drawing

    public function test_a_bank_quiz_serves_only_the_requested_number_of_questions(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(8);
        $quiz = $this->makeBankQuiz($bank, 3);

        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated();

        $response->assertJsonCount(3, 'questions');

        $served = $response->json('questions.*.id');
        $this->assertCount(3, array_unique($served), 'The draw repeated a question.');
    }

    public function test_two_students_get_different_question_sets_from_the_same_bank(): void
    {
        $this->enrollStudent();
        $second = User::factory()->student()->create();
        (new EnrollmentService)->enroll($second, $this->course);

        // Two independent uniform k-of-n draws collide with probability
        // 1 / C(n, k). A 4-of-10 draw collides roughly 1 run in 210, which is
        // far too often for a test; 8 of 20 is 1 in 125,970.
        $bank = $this->makeBank(20);
        $quiz = $this->makeBankQuiz($bank, 8);

        $first = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->json('questions.*.id');
        $other = $this->actingAs($second)->postJson("/api/quizzes/{$quiz->id}/start")->json('questions.*.id');

        $this->assertCount(8, $first);
        $this->assertCount(8, $other);
        $this->assertNotEmpty(array_diff($first, $other), 'Both students were served the identical set.');
    }

    public function test_a_bank_quiz_grades_against_the_drawn_questions(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(5);
        $quiz = $this->makeBankQuiz($bank, 2);

        $started = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start");
        $attemptId = $started->json('attempt.id');
        $questions = $started->json('questions');

        $answers = collect($questions)->map(fn (array $question): array => [
            'question_id' => $question['id'],
            'answer' => $question['options'][0]['id'],
        ])->all();

        $result = $this->actingAs($this->student)
            ->postJson("/api/quiz-attempts/{$attemptId}/submit", ['questions' => $answers])
            ->assertOk();

        $result->assertJsonCount(2, 'questions');
        $this->assertSame(100.0, (float) $result->json('attempt.score_percentage'));
    }

    public function test_a_bank_with_fewer_questions_than_the_draw_size_serves_what_it_has(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(2);
        $quiz = $this->makeBankQuiz($bank, 5);

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated()
            ->assertJsonCount(2, 'questions');
    }

    public function test_starting_an_empty_bank_quiz_is_refused_rather_than_serving_nothing(): void
    {
        $this->enrollStudent();
        $bank = QuestionBank::factory()->create(['course_id' => $this->course->id]);
        $quiz = $this->makeBankQuiz($bank, 3);

        $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors('quiz');

        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    // --------------------------------------------------------- the freeze

    public function test_changing_the_bank_after_a_student_started_does_not_change_their_paper(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(6);
        $quiz = $this->makeBankQuiz($bank, 3);

        $started = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start");
        $servedIds = $started->json('questions.*.id');
        $servedText = $started->json('questions.*.question_text');
        $attemptId = $started->json('attempt.id');

        // Grow and shrink the bank after the attempt was served. The questions
        // already served cannot be removed at all, by the application and then
        // by the schema, so the unserved ones are what can move here.
        $served = QuizAttemptQuestion::where('quiz_attempt_id', $attemptId)->pluck('quiz_question_id');
        $bank->questions()->whereNotIn('id', $served)->delete();

        foreach (range(1, 4) as $index) {
            $added = QuizQuestion::factory()->forBank($bank)->create([
                'question_text' => "Late addition {$index}?",
                'sort_order' => 100 + $index,
            ]);
            QuizOption::factory()->correct()->create(['quiz_question_id' => $added->id]);
        }

        $this->assertDatabaseCount('quiz_attempt_questions', 3);

        // The attempt is still graded and reviewed against its frozen set, even
        // though a fresh draw from the same bank would be a different paper.
        $attempt = QuizAttempt::find($attemptId);
        $this->assertSame($servedIds, $attempt->questions->pluck('id')->all());
        $this->assertSame($servedText, $attempt->questions->pluck('question_text')->all());
    }

    public function test_a_quiz_question_added_after_start_is_not_served_to_that_attempt(): void
    {
        $this->enrollStudent();
        $quiz = Quiz::factory()->for($this->course)->create();
        QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'sort_order' => 1]);

        $started = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start");
        $this->assertCount(1, $started->json('questions'));

        QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'sort_order' => 2]);
        QuizOption::factory()->correct()->create(['quiz_question_id' => QuizQuestion::where('quiz_id', $quiz->id)->max('id')]);

        $attempt = QuizAttempt::find($started->json('attempt.id'));
        $this->assertCount(1, $attempt->questions);
    }

    public function test_grading_uses_the_frozen_set_not_the_questions_added_since(): void
    {
        $this->enrollStudent();
        $quiz = Quiz::factory()->for($this->course)->create();
        $original = QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'sort_order' => 1]);
        $correct = QuizOption::factory()->correct()->create(['quiz_question_id' => $original->id]);

        $started = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start");
        $attemptId = $started->json('attempt.id');

        $late = QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'sort_order' => 2]);
        QuizOption::factory()->correct()->create(['quiz_question_id' => $late->id]);

        $result = $this->actingAs($this->student)
            ->postJson("/api/quiz-attempts/{$attemptId}/submit", [
                'questions' => [
                    ['question_id' => $original->id, 'answer' => $correct->id],
                    ['question_id' => $late->id, 'answer' => $late->options()->first()->id],
                ],
            ])
            ->assertOk();

        // The late question was not served, so it cannot contribute points.
        $result->assertJsonCount(1, 'questions');
        $this->assertSame(1.0, (float) $result->json('attempt.score'));
    }

    public function test_the_database_refuses_to_delete_a_question_an_attempt_used(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(4);
        $quiz = $this->makeBankQuiz($bank, 2);

        $started = $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start");
        $servedId = $started->json('questions.0.id');

        // The application layer refuses first; this asserts the schema backstop
        // still holds if that check is ever removed or bypassed. It also proves
        // the migration kept this foreign key through the SQLite table rebuild
        // that altering `quiz_questions.quiz_id` performs.
        $this->expectException(QueryException::class);

        DB::table('quiz_questions')->where('id', $servedId)->delete();
    }

    // ------------------------------------------------- freezing author edits

    public function test_a_used_bank_question_is_forked_into_a_new_version(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(4);
        $quiz = $this->makeBankQuiz($bank, 1);
        $usedId = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->json('questions.0.id');

        $response = $this->actingAs($this->instructor)
            ->putJson($this->baseUrl("/question-banks/{$bank->id}/questions/{$usedId}"), [
                'type' => 'multiple_choice',
                'question_text' => 'Rewritten after the fact?',
                'points' => 1,
                'options' => [
                    ['option_text' => 'Yes', 'is_correct' => true],
                    ['option_text' => 'No', 'is_correct' => false],
                ],
            ])
            ->assertOk();

        $old = QuizQuestion::find($usedId);
        $newId = $response->json('question.id');

        // The old version is detached but untouched, so the served attempt keeps
        // exactly what the student was shown.
        $this->assertNotSame($usedId, $newId);
        $this->assertSame('Rewritten after the fact?', $response->json('question.question_text'));
        $this->assertSame(2, $response->json('question.version'));
        $this->assertTrue($response->json('forked'));
        $this->assertDatabaseHas('quiz_questions', [
            'id' => $usedId,
            'quiz_id' => null,
            'question_bank_id' => null,
            'replaced_by_id' => $newId,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('quiz_questions', [
            'id' => $newId,
            'question_bank_id' => $bank->id,
            'version' => 2,
        ]);
        $this->assertNotSame('Rewritten after the fact?', $old->fresh()->question_text);
    }

    public function test_a_used_bank_question_cannot_be_deleted(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(4);
        $quiz = $this->makeBankQuiz($bank, 1);
        $usedId = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->json('questions.0.id');

        $this->actingAs($this->instructor)
            ->deleteJson($this->baseUrl("/question-banks/{$bank->id}/questions/{$usedId}"))
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');

        $this->assertDatabaseHas('quiz_questions', ['id' => $usedId]);
    }

    public function test_a_used_quiz_question_is_forked_into_a_new_version(): void
    {
        $this->enrollStudent();
        $quiz = Quiz::factory()->for($this->course)->create();
        $question = QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);

        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start");

        $response = $this->actingAs($this->instructor)
            ->putJson($this->baseUrl("/quizzes/{$quiz->id}/questions/{$question->id}"), [
                'type' => 'multiple_choice',
                'question_text' => 'Rewritten after the fact?',
                'points' => 1,
                'options' => [
                    ['option_text' => 'Yes', 'is_correct' => true],
                    ['option_text' => 'No', 'is_correct' => false],
                ],
            ])
            ->assertOk();

        $newId = $response->json('question.id');

        // The quiz owns the new version; the old one points at it and serves the
        // attempt that already ran.
        $this->assertNotSame($question->id, $newId);
        $this->assertSame(2, $response->json('question.version'));
        $this->assertDatabaseHas('quiz_questions', ['id' => $question->id, 'quiz_id' => null, 'replaced_by_id' => $newId]);
        $this->assertDatabaseHas('quiz_questions', ['id' => $newId, 'quiz_id' => $quiz->id, 'version' => 2]);
        $this->assertSame($question->id, $quiz->openAttemptFor($this->student)->questions->first()->id);
    }

    public function test_an_unused_question_can_still_be_edited(): void
    {
        $bank = $this->makeBank(2);
        $unused = $bank->questions()->firstOrFail();

        $this->actingAs($this->instructor)
            ->putJson($this->baseUrl("/question-banks/{$bank->id}/questions/{$unused->id}"), [
                'type' => 'multiple_choice',
                'question_text' => 'Safe to change?',
                'points' => 2,
                'options' => [
                    ['option_text' => 'Yes', 'is_correct' => true],
                    ['option_text' => 'No', 'is_correct' => false],
                ],
            ])
            ->assertOk();

        $this->assertSame('Safe to change?', $unused->fresh()->question_text);
    }

    public function test_deleting_a_bank_used_by_a_quiz_is_refused(): void
    {
        $bank = $this->makeBank(2);
        $this->makeBankQuiz($bank, 1);

        $this->actingAs($this->instructor)
            ->deleteJson($this->baseUrl("/question-banks/{$bank->id}"))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank');

        $this->assertDatabaseHas('question_banks', ['id' => $bank->id]);
    }

    public function test_deleting_a_bank_with_a_used_question_is_refused(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(4);
        $quiz = $this->makeBankQuiz($bank, 1);
        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start");

        // Detach the quiz so the bank-delete check is what blocks it.
        $quiz->update(['question_bank_id' => null, 'draw_size' => null]);

        $this->actingAs($this->instructor)
            ->deleteJson($this->baseUrl("/question-banks/{$bank->id}"))
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');

        $this->assertDatabaseHas('question_banks', ['id' => $bank->id]);
    }

    // -------------------------------------------------------- authoring api

    public function test_an_instructor_can_create_a_bank_and_add_a_question_to_it(): void
    {
        $bankId = $this->actingAs($this->instructor)
            ->postJson($this->baseUrl('/question-banks'), [
                'title' => 'Photosynthesis pool',
                'description' => 'Reusable across chapters.',
            ])
            ->assertCreated()
            ->json('bank.id');

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/question-banks/{$bankId}/questions"), [
                'type' => 'multiple_choice',
                'question_text' => 'Which pigment absorbs light?',
                'points' => 2,
                'options' => [
                    ['option_text' => 'Chlorophyll', 'is_correct' => true],
                    ['option_text' => 'Keratin', 'is_correct' => false],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('question.in_use', false);

        $this->assertDatabaseHas('quiz_questions', [
            'question_bank_id' => $bankId,
            'quiz_id' => null,
        ]);
    }

    public function test_the_bank_authoring_payload_carries_what_the_builder_needs(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(2);
        $quiz = $this->makeBankQuiz($bank, 1);
        $servedId = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->json('questions.0.id');

        // The builder authors from `show`, so the shape it reads is part of the
        // contract: the summary counts, the bank questions, the quizzes using
        // it, and a per-question `in_use` flag that locks the edit controls.
        $response = $this->actingAs($this->instructor)
            ->getJson($this->baseUrl("/question-banks/{$bank->id}"))
            ->assertOk();

        $response->assertJsonPath('bank.id', $bank->id)
            ->assertJsonPath('bank.questions_count', 2)
            ->assertJsonPath('bank.quizzes.0.id', $quiz->id)
            ->assertJsonPath('bank.quizzes.0.draw_size', 1)
            ->assertJsonCount(2, 'bank.questions')
            ->assertJsonPath('bank.questions.0.options.0.is_correct', true);

        $inUse = collect($response->json('bank.questions'))
            ->mapWithKeys(fn (array $q): array => [$q['id'] => $q['in_use']]);

        $this->assertTrue($inUse->get($servedId), 'A served bank question must report in_use.');
        $this->assertFalse($inUse->get($bank->questions()->where('id', '!=', $servedId)->value('id')));
    }

    public function test_a_bank_from_another_course_cannot_be_edited(): void
    {
        $other = Course::factory()->create();
        $foreign = $this->makeBank(1, $other);

        $this->actingAs($this->instructor)
            ->getJson($this->baseUrl("/question-banks/{$foreign->id}"))
            ->assertNotFound();
    }

    public function test_a_student_cannot_reach_the_bank_api(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(1);

        $this->actingAs($this->student)
            ->getJson($this->baseUrl("/question-banks/{$bank->id}"))
            ->assertForbidden();
    }

    public function test_a_quiz_can_be_created_drawing_from_a_bank(): void
    {
        $bank = $this->makeBank(5);
        $lesson = $this->makeLesson();

        $quizId = $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/lessons/{$lesson->id}/quiz"), [
                'title' => 'Photosynthesis check',
                'passing_score' => 70,
                'attempts_allowed' => 2,
                'question_bank_id' => $bank->id,
                'draw_size' => 3,
            ])
            ->assertCreated()
            ->json('quiz.id');

        $quiz = Quiz::find($quizId);

        $this->assertTrue($quiz->drawsFromBank());
        $this->assertSame($bank->id, $quiz->question_bank_id);
        $this->assertSame(3, $quiz->draw_size);
        $this->assertSame(0, $quiz->questions()->count(), 'A bank quiz must not own any questions.');
        $this->assertSame(3, $quiz->plannedQuestionCount());
    }

    public function test_a_bank_can_be_detached_from_a_quiz_once_it_owns_no_questions(): void
    {
        $bank = $this->makeBank(3);
        $quiz = $this->makeBankQuiz($bank, 2);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Back to its own questions',
                'passing_score' => 70,
                'attempts_allowed' => 1,
            ])
            ->assertOk();

        $quiz->refresh();

        $this->assertFalse($quiz->drawsFromBank());
        $this->assertNull($quiz->draw_size);
    }

    public function test_a_quiz_owning_questions_cannot_be_pointed_at_a_bank(): void
    {
        $bank = $this->makeBank(3);
        $quiz = Quiz::factory()->for($this->course)->create();
        QuizQuestion::factory()->for($quiz)->create();

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Switch me',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_bank_id');

        $this->assertNull($quiz->refresh()->question_bank_id);
    }

    public function test_a_quiz_cannot_draw_a_bank_from_another_course(): void
    {
        $other = Course::factory()->create();
        $foreign = $this->makeBank(3, $other);
        $lesson = $this->makeLesson();

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/lessons/{$lesson->id}/quiz"), [
                'title' => 'Borrowed bank',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $foreign->id,
                'draw_size' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_bank_id');
    }

    public function test_a_draw_size_larger_than_the_bank_is_rejected(): void
    {
        $bank = $this->makeBank(2);
        $lesson = $this->makeLesson();

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/lessons/{$lesson->id}/quiz"), [
                'title' => 'Too many',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 9,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('draw_size');
    }

    public function test_a_bank_quiz_needs_a_draw_size(): void
    {
        $bank = $this->makeBank(2);
        $lesson = $this->makeLesson();

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/lessons/{$lesson->id}/quiz"), [
                'title' => 'No draw size',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('draw_size');
    }

    public function test_switching_a_quiz_to_a_bank_is_refused_while_it_owns_questions(): void
    {
        $bank = $this->makeBank(3);
        $quiz = Quiz::factory()->for($this->course)->create();
        QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);

        $this->actingAs($this->instructor)
            ->putJson($this->baseUrl("/quizzes/{$quiz->id}"), [
                'title' => $quiz->title,
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question_bank_id');

        $this->assertNull($quiz->fresh()->question_bank_id);
    }

    public function test_a_draw_size_without_a_bank_is_rejected(): void
    {
        $quiz = Quiz::factory()->for($this->course)->create();

        $this->actingAs($this->instructor)
            ->putJson($this->baseUrl("/quizzes/{$quiz->id}"), [
                'title' => $quiz->title,
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'draw_size' => 3,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('draw_size');
    }

    // ---------------------------------------------------------- disclosure

    public function test_a_bank_quiz_start_payload_does_not_leak_the_answer_key(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(5);
        $quiz = $this->makeBankQuiz($bank, 2);

        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated();

        $body = $response->getContent();

        // The drawn set is all the student is told about, and it carries no
        // correct flags. A bank question is shared and authoritative, so leaking
        // `is_correct` here would hand over the key for the whole bank.
        $this->assertStringNotContainsString('is_correct', $body);
        $this->assertCount(2, $response->json('questions'));
        $this->assertCount(2, $response->json('questions.*.options'));

        // Every option in a served question is present, but none is flagged.
        foreach ($response->json('questions.*.options') as $option) {
            $this->assertArrayNotHasKey('is_correct', $option);
        }
    }

    public function test_a_bank_quiz_reports_its_planned_question_count_to_students(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(9);
        $quiz = $this->makeBankQuiz($bank, 4);

        $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.questions_count', 4)
            ->assertJsonPath('data.attached_questions_count', 0)
            ->assertJsonPath('data.draw_size', 4);
    }

    public function test_a_fixed_quiz_still_reports_its_own_question_count(): void
    {
        $this->enrollStudent();
        $quiz = Quiz::factory()->for($this->course)->create();
        QuizQuestion::factory()->count(3)->create(['quiz_id' => $quiz->id]);

        $this->actingAs($this->student)
            ->getJson("/api/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.questions_count', 3)
            ->assertJsonPath('data.draw_size', null);
    }

    // ------------------------------------------------------------- model

    public function test_a_question_cannot_belong_to_both_a_quiz_and_a_bank(): void
    {
        $bank = $this->makeBank(1);

        $this->expectException(\LogicException::class);

        QuizQuestion::factory()->create([
            'quiz_id' => Quiz::factory()->create()->id,
            'question_bank_id' => $bank->id,
        ]);
    }

    public function test_a_question_cannot_belong_to_neither(): void
    {
        $this->expectException(\LogicException::class);

        QuizQuestion::factory()->create([
            'quiz_id' => null,
            'question_bank_id' => null,
        ]);
    }
}
