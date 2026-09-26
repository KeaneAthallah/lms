<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\QuizQuestionType;
use App\Services\QuizService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the question types added in Phase 2: `multi_select`, `numeric` and
 * `fill_in_blank`.
 *
 * The emphasis is on the scoring edges, because that is where a grading engine
 * silently does the wrong thing — a duplicated option id from a double-click,
 * a student who over-selects, a skipped blank, a tolerance that only works in
 * one direction.
 */
class QuizQuestionTypeGradingTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- multi_select

    public function test_multi_select_awards_full_points_for_the_exact_correct_set(): void
    {
        [$quiz, $question, $correct] = $this->multiSelect(partialCredit: false);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => $correct,
        ]]);

        $this->assertSame(4.0, (float) $attempt->score);
    }

    public function test_multi_select_ignores_the_order_the_options_were_chosen_in(): void
    {
        [$quiz, $question, $correct] = $this->multiSelect(partialCredit: false);
        $reversed = array_reverse($correct);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => $reversed,
        ]]);

        $this->assertSame(4.0, (float) $attempt->score);
    }

    public function test_multi_select_without_partial_credit_awards_nothing_for_a_partial_set(): void
    {
        [$quiz, $question, $correct] = $this->multiSelect(partialCredit: false);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => [$correct[0]],
        ]]);

        $this->assertSame(0.0, (float) $attempt->score);
    }

    public function test_multi_select_with_partial_credit_scales_with_correct_picks(): void
    {
        // 2 of 3 correct out of 3 needed => 2/3 of 4 points.
        [$quiz, $question, $correct, $wrong] = $this->multiSelect(partialCredit: true);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => [$correct[0], $correct[1]],
        ]]);

        $this->assertEqualsWithDelta(2.67, (float) $attempt->score, 0.01);
    }

    public function test_multi_select_penalises_a_wrong_pick_under_partial_credit(): void
    {
        // 2 correct minus 1 wrong, over 3 needed => 1/3 of 4 points.
        [$quiz, $question, $correct, $wrong] = $this->multiSelect(partialCredit: true);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => [$correct[0], $correct[1], $wrong[0]],
        ]]);

        $this->assertEqualsWithDelta(1.33, (float) $attempt->score, 0.01);
    }

    public function test_multi_select_credits_dedupe_against_wrong_picks_not_just_hits(): void
    {
        // All three correct plus both wrong picks: (3 - 2) / 3, so 1.33 of 4.
        // This is the case that stops "select everything" from scoring full marks.
        [$quiz, $question, $correct, $wrong] = $this->multiSelect(partialCredit: true);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => [...$correct, ...$wrong],
        ]]);

        $this->assertEqualsWithDelta(1.33, (float) $attempt->score, 0.01);
    }

    public function test_multi_select_never_awards_a_negative_score(): void
    {
        [$quiz, $question, $correct, $wrong] = $this->multiSelect(partialCredit: true);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => $wrong,
        ]]);

        $this->assertSame(0.0, (float) $attempt->score);
    }

    public function test_multi_select_does_not_double_count_a_duplicated_option_id(): void
    {
        [$quiz, $question, $correct] = $this->multiSelect(partialCredit: true);

        // A double-click must not inflate the ratio above 1.
        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => [...$correct, $correct[0], $correct[0]],
        ]]);

        $this->assertSame(4.0, (float) $attempt->score);
    }

    public function test_multi_select_accepts_a_bare_scalar_for_a_single_pick(): void
    {
        [$quiz, $question, $correct] = $this->multiSelect(partialCredit: false);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => $correct[0],
        ]]);

        $this->assertSame(0.0, (float) $attempt->score, 'One of two correct is not the exact set.');
    }

    // -------------------------------------------------------------------- numeric

    public function test_numeric_awards_full_points_for_an_exact_match(): void
    {
        [$quiz, $question] = $this->numeric(accepted: '9.81', tolerance: 0);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => '9.81',
        ]]);

        $this->assertSame(3.0, (float) $attempt->score);
    }

    public function test_numeric_awards_full_points_inside_the_tolerance(): void
    {
        [$quiz, $question] = $this->numeric(accepted: '9.81', tolerance: 0.5);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => '9.5',
        ]]);

        $this->assertSame(3.0, (float) $attempt->score);
    }

    public function test_numeric_awards_nothing_outside_the_tolerance(): void
    {
        [$quiz, $question] = $this->numeric(accepted: '9.81', tolerance: 0.5);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => '9.0',
        ]]);

        $this->assertSame(0.0, (float) $attempt->score);
    }

    public function test_numeric_tolerance_is_symmetric(): void
    {
        // The author accepts 10 with a tolerance of 0.5; 10.5 must pass, and so
        // must a submitted 10 against an accepted 10.5.
        [$quiz, $question] = $this->numeric(accepted: '10', tolerance: 0.5);

        $this->assertSame(3.0, (float) $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => '10.5',
        ]])->score);

        [$quiz, $question] = $this->numeric(accepted: '10.5', tolerance: 0.5);

        $this->assertSame(3.0, (float) $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => '10',
        ]])->score);
    }

    public function test_numeric_tolerates_trailing_units(): void
    {
        [$quiz, $question] = $this->numeric(accepted: '9.81', tolerance: 0);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => '9.81 m/s^2',
        ]]);

        $this->assertSame(3.0, (float) $attempt->score);
    }

    public function test_numeric_awards_nothing_for_a_non_numeric_answer(): void
    {
        [$quiz, $question] = $this->numeric(accepted: '9.81', tolerance: 5);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => 'about nine point eight',
        ]]);

        $this->assertSame(0.0, (float) $attempt->score);
    }

    public function test_numeric_awards_nothing_for_a_negative_when_the_answer_is_positive(): void
    {
        [$quiz, $question] = $this->numeric(accepted: '5', tolerance: 0);

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => '-5',
        ]]);

        $this->assertSame(0.0, (float) $attempt->score);
    }

    // -------------------------------------------------------------- fill_in_blank

    public function test_fill_in_blank_awards_full_points_when_every_blank_is_correct(): void
    {
        [$quiz, $question] = $this->fillInBlank(
            text: 'The capital of {{1}} is {{2}}.',
            blanks: ['1' => ['Paris'], '2' => ['France']],
            partialCredit: false,
        );

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => ['1' => 'Paris', '2' => 'France'],
        ]]);

        $this->assertSame(5.0, (float) $attempt->score);
    }

    public function test_fill_in_blank_accepts_any_listed_alternative(): void
    {
        [$quiz, $question] = $this->fillInBlank(
            text: 'The capital of {{1}} is {{2}}.',
            blanks: ['1' => ['Paris', 'City of Light'], '2' => ['France', 'French Republic']],
            partialCredit: false,
        );

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => ['1' => 'city of light', '2' => 'French Republic'],
        ]]);

        $this->assertSame(5.0, (float) $attempt->score);
    }

    public function test_fill_in_blank_without_partial_credit_awards_nothing_for_a_missed_blank(): void
    {
        [$quiz, $question] = $this->fillInBlank(
            text: 'The capital of {{1}} is {{2}}.',
            blanks: ['1' => ['Paris'], '2' => ['France']],
            partialCredit: false,
        );

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => ['1' => 'Paris'],
        ]]);

        $this->assertSame(0.0, (float) $attempt->score);
    }

    public function test_fill_in_blank_with_partial_credit_scales_per_blank(): void
    {
        // 1 of 2 correct => half of 5 points.
        [$quiz, $question] = $this->fillInBlank(
            text: 'The capital of {{1}} is {{2}}.',
            blanks: ['1' => ['Paris'], '2' => ['France']],
            partialCredit: true,
        );

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => ['1' => 'Paris', '2' => 'Berlin'],
        ]]);

        $this->assertEqualsWithDelta(2.5, (float) $attempt->score, 0.01);
    }

    public function test_fill_in_blank_matches_blanks_by_index_not_position(): void
    {
        // Answering only blank 2 must be graded as blank 2, not read as blank 1.
        [$quiz, $question] = $this->fillInBlank(
            text: 'The capital of {{1}} is {{2}}.',
            blanks: ['1' => ['Paris'], '2' => ['France']],
            partialCredit: true,
        );

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => ['2' => 'France'],
        ]]);

        $this->assertEqualsWithDelta(2.5, (float) $attempt->score, 0.01);
    }

    public function test_fill_in_blank_ignores_accepted_answers_for_absent_placeholders(): void
    {
        // settings lists blank 9, which the text does not contain. Grading must
        // not require an answer to a blank the student cannot see.
        [$quiz, $question] = $this->fillInBlank(
            text: 'The capital of {{1}} is Paris.',
            blanks: ['1' => ['France'], '9' => ['nonsense']],
            partialCredit: false,
        );

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => ['1' => 'France'],
        ]]);

        $this->assertSame(5.0, (float) $attempt->score);
    }

    public function test_fill_in_blank_awards_nothing_when_nothing_is_answered(): void
    {
        [$quiz, $question] = $this->fillInBlank(
            text: 'The capital of {{1}} is {{2}}.',
            blanks: ['1' => ['Paris'], '2' => ['France']],
            partialCredit: true,
        );

        $attempt = $this->submit($quiz, [[
            'question_id' => $question->id,
            'answer' => ['1' => '', '2' => ''],
        ]]);

        $this->assertSame(0.0, (float) $attempt->score);
    }

    // ------------------------------------------------------- answer key disclosure

    public function test_the_student_start_payload_never_carries_the_fill_in_blank_answer_key(): void
    {
        [$quiz] = $this->fillInBlank(
            text: 'The capital of {{1}} is {{2}}.',
            blanks: ['1' => ['Paris'], '2' => ['France']],
        );

        $student = $this->enrolledOn($quiz);
        $response = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/start")->assertCreated()->json();

        $encoded = json_encode($response);

        $this->assertStringNotContainsString('Paris', $encoded);
        $this->assertStringNotContainsString('France', $encoded);
        $this->assertArrayNotHasKey('blanks', $response['questions'][0]['settings']);
    }

    public function test_the_student_start_payload_discloses_the_scoring_mode_but_not_the_tolerance(): void
    {
        [$quiz] = $this->numeric(accepted: '9.81', tolerance: 0.25);

        $student = $this->enrolledOn($quiz);
        $response = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/start")->json();

        $this->assertArrayNotHasKey('tolerance', $response['questions'][0]['settings']);
        $this->assertStringNotContainsString('9.81', json_encode($response));
    }

    public function test_multi_select_tells_the_student_whether_partial_credit_applies(): void
    {
        [$quiz] = $this->multiSelect(partialCredit: true);

        $student = $this->enrolledOn($quiz);
        $response = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/start")->json();

        $this->assertTrue($response['questions'][0]['settings']['partial_credit']);
    }

    // -------------------------------------------------------------------- helpers

    /**
     * @param  array<int, array{question_id: int, answer: mixed}>  $answers
     */
    private function submit(Quiz $quiz, array $answers): QuizAttempt
    {
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($quiz->course)->create();

        $service = app(QuizService::class);

        return $service->submit($service->start($quiz, $student), ['questions' => $answers]);
    }

    /**
     * @return array{0: Quiz, 1: QuizQuestion, 2: array<int, int>, 3: array<int, int>}
     */
    private function multiSelect(bool $partialCredit): array
    {
        $quiz = Quiz::factory()->for($this->course())->create();
        $question = QuizQuestion::factory()->for($quiz)->create([
            'type' => QuizQuestionType::MultiSelect,
            'points' => 4,
            'settings' => ['partial_credit' => $partialCredit],
        ]);

        $question->options()->createMany([
            ['option_text' => 'A', 'is_correct' => true, 'sort_order' => 0],
            ['option_text' => 'B', 'is_correct' => true, 'sort_order' => 1],
            ['option_text' => 'C', 'is_correct' => true, 'sort_order' => 2],
            ['option_text' => 'D', 'is_correct' => false, 'sort_order' => 3],
            ['option_text' => 'E', 'is_correct' => false, 'sort_order' => 4],
        ]);

        $question->load('options');

        $correct = $question->options->where('is_correct', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $wrong = $question->options->where('is_correct', false)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return [$quiz, $question, $correct, $wrong];
    }

    /**
     * @return array{0: Quiz, 1: QuizQuestion}
     */
    private function numeric(string $accepted, float $tolerance): array
    {
        $quiz = Quiz::factory()->for($this->course())->create();
        $question = QuizQuestion::factory()->for($quiz)->create([
            'type' => QuizQuestionType::Numeric,
            'points' => 3,
            'settings' => ['answer' => $accepted, 'tolerance' => $tolerance],
        ]);

        return [$quiz, $question];
    }

    /**
     * @param  array<string, array<int, string>>  $blanks
     * @return array{0: Quiz, 1: QuizQuestion}
     */
    private function fillInBlank(string $text, array $blanks, bool $partialCredit = false): array
    {
        $quiz = Quiz::factory()->for($this->course())->create();
        $question = QuizQuestion::factory()->for($quiz)->create([
            'type' => QuizQuestionType::FillInBlank,
            'points' => 5,
            'question_text' => $text,
            'settings' => ['blanks' => $blanks, 'partial_credit' => $partialCredit],
        ]);

        return [$quiz, $question->load('options')];
    }

    /**
     * A student enrolled on the course that actually owns the quiz, since
     * `take` is enrollment-only and a student enrolled elsewhere gets a 403.
     */
    private function enrolledOn(Quiz $quiz): User
    {
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($quiz->course)->create();

        return $student;
    }

    private function course(): Course
    {
        return Course::factory()->for(User::factory()->instructor(), 'instructor')->create();
    }
}
