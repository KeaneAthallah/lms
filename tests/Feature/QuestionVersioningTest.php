<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Forking an authored question instead of mutating it in place.
 *
 * The freeze record makes a paper immutable once served, but that only protects
 * the attempt; the question the author is editing would still be rewritten under
 * every future paper. The fix is to treat an in-use question as read-only and
 * write the author's change as a new version that the quiz/bank now owns, while
 * the served version is detached and left pointing at its successor. These tests
 * pin that behavior down at the seam where the earlier version has to keep
 * serving the attempt that already ran.
 */
class QuestionVersioningTest extends TestCase
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

    private function enrollStudent(?User $student = null): void
    {
        (new EnrollmentService)->enroll($student ?? $this->student, $this->course);
    }

    private function makeQuestion(Quiz $quiz, string $text, int $sortOrder = 1): QuizQuestion
    {
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'sort_order' => $sortOrder,
            'question_text' => $text,
        ]);

        QuizOption::factory()->correct()->create(['quiz_question_id' => $question->id, 'sort_order' => 1]);

        return $question;
    }

    private function editQuizQuestion(Quiz $quiz, QuizQuestion $question, string $text): TestResponse
    {
        return $this->actingAs($this->instructor)->putJson(
            $this->baseUrl("/quizzes/{$quiz->id}/questions/{$question->id}"),
            [
                'type' => 'multiple_choice',
                'question_text' => $text,
                'points' => 1,
                'options' => [
                    ['option_text' => 'Yes', 'is_correct' => true],
                    ['option_text' => 'No', 'is_correct' => false],
                ],
            ],
        );
    }

    public function test_an_in_progress_attempt_keeps_the_version_it_was_served_after_an_edit(): void
    {
        $this->enrollStudent();
        $quiz = Quiz::factory()->for($this->course)->create();
        $question = $this->makeQuestion($quiz, 'Original?');

        $attemptId = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated()
            ->json('attempt.id');

        $response = $this->editQuizQuestion($quiz, $question, 'Edited?')->assertOk();
        $this->assertTrue($response->json('forked'));

        // The attempt still holds the exact row it was served, so reopening the
        // paper shows what the student answered rather than the author's rewrite.
        $attempt = QuizAttempt::find($attemptId);
        $this->assertSame($question->id, $attempt->questions->first()->id);
        $this->assertSame('Original?', $attempt->questions->first()->question_text);
    }

    public function test_a_new_attempt_after_an_edit_serves_the_new_version(): void
    {
        $this->enrollStudent();
        $second = User::factory()->student()->create();
        $this->enrollStudent($second);

        $quiz = Quiz::factory()->for($this->course)->create();
        $question = $this->makeQuestion($quiz, 'Original?');

        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->assertCreated();

        $newId = $this->editQuizQuestion($quiz, $question, 'Edited?')
            ->assertOk()
            ->json('question.id');

        // A fresh draw reads the head the quiz now owns, so the rewrite reaches
        // future papers without disturbing the one already in flight.
        $served = $this->actingAs($second)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated();

        $this->assertSame($newId, $served->json('questions.0.id'));
        $this->assertSame('Edited?', $served->json('questions.0.question_text'));
    }

    public function test_the_version_chain_advances_each_time_a_served_version_is_edited(): void
    {
        $this->enrollStudent();
        $second = User::factory()->student()->create();
        $this->enrollStudent($second);

        $quiz = Quiz::factory()->for($this->course)->create();
        $v1 = $this->makeQuestion($quiz, 'First?');

        $firstAttemptId = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->json('attempt.id');

        $v2Id = $this->editQuizQuestion($quiz, $v1, 'Second?')
            ->assertOk()
            ->json('question.id');

        $secondAttemptId = $this->actingAs($second)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->json('attempt.id');

        $v3Id = $this->editQuizQuestion($quiz, QuizQuestion::findOrFail($v2Id), 'Third?')
            ->assertOk()
            ->json('question.id');

        // Each served version is detached toward the one that replaced it, so the
        // chain v1 -> v2 -> v3 is walkable and the quiz always owns the newest.
        $this->assertDatabaseHas('quiz_questions', [
            'id' => $v1->id,
            'quiz_id' => null,
            'replaced_by_id' => $v2Id,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('quiz_questions', [
            'id' => $v2Id,
            'quiz_id' => null,
            'replaced_by_id' => $v3Id,
            'version' => 2,
        ]);
        $this->assertDatabaseHas('quiz_questions', [
            'id' => $v3Id,
            'quiz_id' => $quiz->id,
            'replaced_by_id' => null,
            'version' => 3,
        ]);

        $this->assertSame($v1->id, QuizAttempt::find($firstAttemptId)->questions->first()->id);
        $this->assertSame($v2Id, QuizAttempt::find($secondAttemptId)->questions->first()->id);
    }

    public function test_an_unused_question_is_edited_in_place_and_stays_version_one(): void
    {
        $quiz = Quiz::factory()->for($this->course)->create();
        $question = $this->makeQuestion($quiz, 'Original?');

        $response = $this->editQuizQuestion($quiz, $question, 'Edited?')->assertOk();

        // Nothing has been served from this row, so there is no history to
        // protect and no reason to version it.
        $this->assertFalse($response->json('forked'));
        $this->assertSame($question->id, $response->json('question.id'));
        $this->assertSame(1, $response->json('question.version'));
        $this->assertSame('Edited?', $question->fresh()->question_text);
    }

    public function test_the_quiz_authoring_payload_reports_version_and_in_use(): void
    {
        $this->enrollStudent();
        $quiz = Quiz::factory()->for($this->course)->create();
        $served = $this->makeQuestion($quiz, 'Served?');

        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->assertCreated();

        // Added after the attempt started, so it is not part of the served set.
        $spare = $this->makeQuestion($quiz, 'Spare?', 2);

        $questions = collect(
            $this->actingAs($this->instructor)
                ->getJson($this->baseUrl("/quizzes/{$quiz->id}"))
                ->assertOk()
                ->json('quiz.questions')
        )->keyBy('id');

        $this->assertTrue($questions[$served->id]['in_use']);
        $this->assertSame(1, $questions[$served->id]['version']);
        $this->assertFalse($questions[$spare->id]['in_use']);
    }

    public function test_a_used_quiz_question_cannot_be_deleted(): void
    {
        $this->enrollStudent();
        $quiz = Quiz::factory()->for($this->course)->create();
        $question = $this->makeQuestion($quiz, 'Served?');

        $this->actingAs($this->student)->postJson("/api/quizzes/{$quiz->id}/start")->assertCreated();

        $this->actingAs($this->instructor)
            ->deleteJson($this->baseUrl("/quizzes/{$quiz->id}/questions/{$question->id}"))
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');

        $this->assertDatabaseHas('quiz_questions', ['id' => $question->id]);
    }
}
