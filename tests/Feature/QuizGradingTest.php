<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\QuizAttemptStatus;
use App\QuizQuestionType;
use App\Services\QuizService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizGradingTest extends TestCase
{
    use RefreshDatabase;

    public function test_choice_question_awards_full_points_for_the_correct_option(): void
    {
        [$quiz, $question] = $this->quizWithChoice();

        $attempt = $this->submit($quiz, [['question_id' => $question->id, 'answer' => $question->options->firstWhere('is_correct', true)->id]]);

        $this->assertSame(1, (int) $attempt->answers->firstWhere('quiz_question_id', $question->id)->points_earned);
    }

    public function test_choice_question_awards_nothing_for_a_wrong_option(): void
    {
        [$quiz, $question] = $this->quizWithChoice();

        $wrong = $question->options->firstWhere('is_correct', false);

        $attempt = $this->submit($quiz, [['question_id' => $question->id, 'answer' => $wrong->id]]);

        $this->assertSame(0.0, (float) $attempt->answers->firstWhere('quiz_question_id', $question->id)->points_earned);
        $this->assertFalse((bool) $attempt->passed);
    }

    public function test_unanswered_question_awards_nothing_and_is_still_recorded(): void
    {
        [$quiz, $question] = $this->quizWithChoice();

        $attempt = $this->submit($quiz, []);

        $answer = $attempt->answers->firstWhere('quiz_question_id', $question->id);

        $this->assertNotNull($answer, 'An unanswered question must still produce an answer row so the attempt is complete.');
        $this->assertSame(0.0, (float) $answer->points_earned);
    }

    public function test_true_false_question_grades_as_a_two_option_choice(): void
    {
        [$quiz, $question] = $this->quizWithChoice(QuizQuestionType::TrueFalse);

        $attempt = $this->submit($quiz, [['question_id' => $question->id, 'answer' => $question->options->firstWhere('is_correct', true)->id]]);

        $this->assertSame(1, (int) $attempt->answers->firstWhere('quiz_question_id', $question->id)->points_earned);
    }

    public function test_short_answer_matching_is_case_and_whitespace_insensitive(): void
    {
        [$quiz, $question] = $this->quizWithShortAnswer('Laravel');

        $attempt = $this->submit($quiz, [['question_id' => $question->id, 'answer' => '  laravel  ']]);

        $this->assertSame(1, (int) $attempt->answers->firstWhere('quiz_question_id', $question->id)->points_earned);
    }

    public function test_short_answer_mismatch_awards_nothing(): void
    {
        [$quiz, $question] = $this->quizWithShortAnswer('Laravel');

        $attempt = $this->submit($quiz, [['question_id' => $question->id, 'answer' => 'Symfony']]);

        $this->assertSame(0.0, (float) $attempt->answers->firstWhere('quiz_question_id', $question->id)->points_earned);
    }

    public function test_short_answer_persists_the_raw_response_not_a_normalised_one(): void
    {
        [$quiz, $question] = $this->quizWithShortAnswer('Laravel');

        $attempt = $this->submit($quiz, [['question_id' => $question->id, 'answer' => '  laravel  ']]);

        $this->assertSame('  laravel  ', $attempt->answers->firstWhere('quiz_question_id', $question->id)->answer);
    }

    public function test_question_with_no_correct_option_awards_nothing_rather_than_defaulting(): void
    {
        $quiz = Quiz::factory()->for($this->course())->create(['passing_score' => 0]);
        $question = QuizQuestion::factory()->for($quiz)->create(['type' => QuizQuestionType::MultipleChoice]);
        $question->options()->createMany([
            ['option_text' => 'Neither', 'is_correct' => false, 'sort_order' => 0],
        ]);

        $attempt = $this->submit($quiz, [['question_id' => $question->id, 'answer' => $question->options->first()->id]]);

        $this->assertSame(0.0, (float) $attempt->answers->firstWhere('quiz_question_id', $question->id)->points_earned);
    }

    public function test_choice_answer_is_serialised_as_the_option_id(): void
    {
        [$quiz, $question] = $this->quizWithChoice();

        $correct = $question->options->firstWhere('is_correct', true);

        $attempt = $this->submit($quiz, [['question_id' => $question->id, 'answer' => $correct->id]]);

        $this->assertSame((string) $correct->id, $attempt->answers->firstWhere('quiz_question_id', $question->id)->answer);
    }

    public function test_a_quiz_not_attached_to_a_lesson_can_still_be_started_and_submitted(): void
    {
        $quiz = Quiz::factory()->for($this->course())->create();
        $question = QuizQuestion::factory()->for($quiz)->create(['points' => 1]);
        $question->options()->createMany([
            ['option_text' => 'Right', 'is_correct' => true, 'sort_order' => 0],
        ]);

        $this->assertNull($quiz->lesson);

        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($quiz->course)->create();

        $service = app(QuizService::class);
        $attempt = $service->start($quiz, $student);

        $this->assertSame(QuizAttemptStatus::InProgress, $attempt->status);

        $graded = $service->submit($attempt, ['questions' => [
            ['question_id' => $question->id, 'answer' => $question->options->first()->id],
        ]]);

        $this->assertSame(1.0, (float) $graded->score);
    }

    /**
     * @param  array<int, array{question_id: int, answer: mixed}>  $answers
     */
    private function submit(Quiz $quiz, array $answers): QuizAttempt
    {
        $student = User::factory()->student()->create();

        Enrollment::factory()->for($student, 'student')->for($quiz->course)->create();

        $attempt = app(QuizService::class)->start($quiz, $student);

        return app(QuizService::class)->submit($attempt, ['questions' => $answers]);
    }

    /**
     * @return array{0: Quiz, 1: QuizQuestion}
     */
    private function quizWithChoice(QuizQuestionType $type = QuizQuestionType::MultipleChoice): array
    {
        $quiz = Quiz::factory()->for($this->course())->create();

        $question = QuizQuestion::factory()->for($quiz)->create(['type' => $type, 'points' => 1]);
        $question->options()->createMany([
            ['option_text' => 'Right', 'is_correct' => true, 'sort_order' => 0],
            ['option_text' => 'Wrong', 'is_correct' => false, 'sort_order' => 1],
        ]);

        return [$quiz->fresh(), $question->fresh()];
    }

    /**
     * @return array{0: Quiz, 1: QuizQuestion}
     */
    private function quizWithShortAnswer(string $accepted): array
    {
        $quiz = Quiz::factory()->for($this->course())->create();

        $question = QuizQuestion::factory()->for($quiz)->create([
            'type' => QuizQuestionType::ShortAnswer,
            'points' => 1,
        ]);
        $question->options()->createMany([
            ['option_text' => $accepted, 'is_correct' => true, 'sort_order' => 0],
        ]);

        return [$quiz->fresh(), $question->fresh()];
    }

    private function course(): Course
    {
        return Course::factory()->for(User::factory()->instructor(), 'instructor')->create();
    }
}
