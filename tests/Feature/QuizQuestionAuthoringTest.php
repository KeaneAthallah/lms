<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use App\QuizQuestionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authoring rules for the Phase 2 question types.
 *
 * The point of these is catching bad content at save time. A fill-in-the-blank
 * whose accepted answers do not line up with its placeholders, or a numeric
 * question with no accepted value, both grade every student to zero forever
 * with no error anywhere — the failure is invisible until someone complains.
 */
class QuizQuestionAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Quiz $quiz;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create();
        $this->course = Course::factory()->for($this->instructor, 'instructor')->create();
        $this->quiz = Quiz::factory()->for($this->course)->create();
    }

    public function test_a_fill_in_blank_question_saves_with_its_accepted_answers(): void
    {
        $this->store([
            'type' => QuizQuestionType::FillInBlank->value,
            'question_text' => 'The capital of {{1}} is {{2}}.',
            'settings' => ['blanks' => ['1' => ['Paris'], '2' => ['France']]],
        ])->assertCreated();

        $question = QuizQuestion::where('question_text', 'The capital of {{1}} is {{2}}.')->firstOrFail();

        $this->assertSame(['1' => ['Paris'], '2' => ['France']], $question->settings['blanks']);
    }

    public function test_a_fill_in_blank_question_without_placeholders_is_rejected(): void
    {
        $this->store([
            'type' => QuizQuestionType::FillInBlank->value,
            'question_text' => 'The capital of France is Paris.',
            'settings' => ['blanks' => ['1' => ['Paris']]],
        ])->assertJsonValidationErrors(['question_text']);
    }

    public function test_a_fill_in_blank_with_no_accepted_answers_is_rejected(): void
    {
        $this->store([
            'type' => QuizQuestionType::FillInBlank->value,
            'question_text' => 'The capital of {{1}} is Paris.',
            'settings' => ['blanks' => []],
        ])->assertJsonValidationErrors(['settings.blanks']);
    }

    public function test_a_placeholder_with_no_accepted_answers_is_rejected(): void
    {
        // The whole point: blank 2 appears in the text but has no answers, so
        // every student would be silently marked wrong on it.
        $this->store([
            'type' => QuizQuestionType::FillInBlank->value,
            'question_text' => 'The capital of {{1}} is {{2}}.',
            'settings' => ['blanks' => ['1' => ['Paris']]],
        ])->assertJsonValidationErrors(['settings.blanks']);
    }

    public function test_a_blank_with_an_empty_alternatives_list_is_rejected(): void
    {
        $this->store([
            'type' => QuizQuestionType::FillInBlank->value,
            'question_text' => 'The capital of {{1}} is Paris.',
            'settings' => ['blanks' => ['1' => []]],
        ])->assertJsonValidationErrors(['settings.blanks.1']);
    }

    public function test_a_numeric_question_saves_with_a_hidden_accepted_answer(): void
    {
        $this->store([
            'type' => QuizQuestionType::Numeric->value,
            'question_text' => 'What is gravitational acceleration?',
            'settings' => ['answer' => 9.81, 'tolerance' => 0.05],
        ])->assertCreated();

        $question = QuizQuestion::where('question_text', 'What is gravitational acceleration?')->firstOrFail();

        $this->assertSame(9.81, $question->settings['answer']);
        $this->assertSame(0.05, $question->settings['tolerance']);
    }

    public function test_a_numeric_question_without_an_accepted_answer_is_rejected(): void
    {
        $this->store([
            'type' => QuizQuestionType::Numeric->value,
            'question_text' => 'What is gravitational acceleration?',
            'settings' => ['tolerance' => 0.05],
        ])->assertJsonValidationErrors(['settings.answer']);
    }

    public function test_a_negative_numeric_tolerance_is_rejected(): void
    {
        $this->store([
            'type' => QuizQuestionType::Numeric->value,
            'question_text' => 'What is gravitational acceleration?',
            'settings' => ['answer' => 9.81, 'tolerance' => -1],
        ])->assertJsonValidationErrors(['settings.tolerance']);
    }

    public function test_a_setting_that_does_not_apply_to_the_type_is_rejected(): void
    {
        // A tolerance on a multiple choice question would be ignored by the
        // grader, so the author would think they had configured something.
        $this->store([
            'type' => QuizQuestionType::MultipleChoice->value,
            'question_text' => 'Pick one.',
            'settings' => ['tolerance' => 0.5],
            'options' => $this->optionRows(correct: 0),
        ])->assertJsonValidationErrors(['settings.tolerance']);
    }

    public function test_fill_in_blank_answers_are_not_stored_as_student_visible_options(): void
    {
        $this->store([
            'type' => QuizQuestionType::FillInBlank->value,
            'question_text' => 'The capital of {{1}} is Paris.',
            'settings' => ['blanks' => ['1' => ['France']]],
        ])->assertCreated();

        $question = QuizQuestion::where('question_text', 'The capital of {{1}} is Paris.')->firstOrFail();

        $this->assertCount(0, $question->options, 'The answer key must not be rendered as a choice.');
    }

    public function test_a_multi_select_question_requires_options_to_be_marked_correct(): void
    {
        $this->store([
            'type' => QuizQuestionType::MultiSelect->value,
            'question_text' => 'Pick the frameworks.',
            'options' => [
                ['option_text' => 'Laravel'],
                ['option_text' => 'Symfony'],
            ],
        ])->assertJsonValidationErrors(['options.0.is_correct', 'options.1.is_correct']);
    }

    public function test_a_multi_select_question_saves_with_partial_credit_enabled(): void
    {
        $this->store([
            'type' => QuizQuestionType::MultiSelect->value,
            'question_text' => 'Pick the frameworks.',
            'settings' => ['partial_credit' => true],
            'options' => $this->optionRows(correct: 0, correctCount: 2),
        ])->assertCreated();

        $question = QuizQuestion::where('question_text', 'Pick the frameworks.')->firstOrFail();

        $this->assertTrue($question->settings['partial_credit']);
        $this->assertCount(2, $question->options->where('is_correct', true));
    }

    public function test_appended_questions_get_an_increasing_sort_order(): void
    {
        // Appended questions used to all land on the column default of 0, so the
        // quiz rendered in whatever order the database returned.
        $this->store([
            'type' => QuizQuestionType::MultipleChoice->value,
            'question_text' => 'First.',
            'options' => $this->optionRows(correct: 0),
        ])->assertCreated();

        $this->store([
            'type' => QuizQuestionType::MultipleChoice->value,
            'question_text' => 'Second.',
            'options' => $this->optionRows(correct: 0),
        ])->assertCreated();

        $this->store([
            'type' => QuizQuestionType::MultipleChoice->value,
            'question_text' => 'Third.',
            'options' => $this->optionRows(correct: 0),
        ])->assertCreated();

        $orders = array_map(
            'intval',
            QuizQuestion::where('quiz_id', $this->quiz->id)->orderBy('id')->pluck('sort_order')->all(),
        );

        // The property that matters is that each appended question lands after
        // the last, whatever the existing maximum happens to be. Asserting exact
        // values would pin the factory's seed instead of the behaviour.
        $this->assertCount(3, array_unique($orders));

        $sorted = $orders;
        sort($sorted);

        $this->assertSame($orders, $sorted, 'Sort orders must already be in insertion order.');
    }

    public function test_a_question_explanation_round_trips(): void
    {
        // The controller has always written `explanation` on a question, but no
        // such column existed, so it was silently dropped.
        $this->store([
            'type' => QuizQuestionType::MultipleChoice->value,
            'question_text' => 'Pick one.',
            'explanation' => 'Because the framework compiles views.',
            'options' => $this->optionRows(correct: 0),
        ])->assertCreated();

        $question = QuizQuestion::where('question_text', 'Pick one.')->firstOrFail();

        $this->assertSame('Because the framework compiles views.', $question->explanation);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(array $payload)
    {
        CourseSection::factory()->for($this->course)->create();

        return $this->actingAs($this->instructor)->postJson(
            "/api/instructor/courses/{$this->course->slug}/quizzes/{$this->quiz->id}/questions",
            array_merge(['points' => 1], $payload),
        );
    }

    /**
     * @return array<int, array{option_text: string, is_correct: bool}>
     */
    private function optionRows(int $correct, int $correctCount = 1): array
    {
        $options = [
            ['option_text' => 'One', 'is_correct' => $correct === 0],
            ['option_text' => 'Two', 'is_correct' => $correct === 1],
            ['option_text' => 'Three', 'is_correct' => $correct === 2],
        ];

        if ($correctCount > 1) {
            $options[1]['is_correct'] = true;
        }

        return $options;
    }
}
