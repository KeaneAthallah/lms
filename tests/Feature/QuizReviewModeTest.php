<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review mode: the durable, addressable per-attempt report.
 *
 * Before this the question-by-question review existed in exactly one place --
 * the result page shown straight after submitting -- and was held in component
 * memory. Leave the page and it was gone: the overview only said how many
 * attempts were used, there was no list to pick from, and nothing linked back to
 * a past attempt. The report endpoint and the frozen-paper renderer already
 * existed; the missing piece was anything pointing at them.
 */
class QuizReviewModeTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- overview history

    public function test_the_overview_lists_the_student_graded_attempts(): void
    {
        $s = $this->scenario();
        $this->start($s);
        $this->submit($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $data = $this->overview($s)->json('data');
        $row = collect($data['attempts'])->firstWhere('id', $this->attemptId($s));

        $this->assertNotNull($row);
        $this->assertTrue($row['reviewable']);
        $this->assertSame('completed', $row['status']);
        $this->assertTrue($row['passed']);
        $this->assertEquals(1.0, $row['score']);
        // One of the two questions answered: 1 of 1.5 points.
        $this->assertEquals(66.67, $row['score_percentage']);
    }

    public function test_an_expired_attempt_is_reviewable_from_the_overview(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);
        $this->start($s);
        $s['attemptId'] = $this->attemptId($s);
        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $this->travel(31)->minutes();

        // Closing it is what Start does: it sees the deadline passed and grades
        // the attempt rather than resuming it, then mints the next one. The
        // expired paper is the earlier attempt, not the fresh in-progress one.
        $this->start($s);

        $row = collect($this->overview($s)->json('data.attempts'))->firstWhere('id', $s['attemptId']);

        $this->assertSame('expired', $row['status']);
        $this->assertTrue($row['reviewable']);
        $this->assertTrue($row['passed']);
        $this->assertEquals(1.0, $row['score']);
    }

    public function test_an_in_progress_attempt_is_not_reviewable(): void
    {
        $s = $this->scenario();
        $this->start($s);

        $row = collect($this->overview($s)->json('data.attempts'))->firstWhere('id', $this->attemptId($s));

        $this->assertSame('in_progress', $row['status']);
        $this->assertFalse($row['reviewable']);
    }

    public function test_the_overview_only_lists_the_students_own_attempts(): void
    {
        $s = $this->scenario();
        $this->start($s);
        $this->submit($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $other = User::factory()->student()->create();
        Enrollment::factory()->create([
            'course_id' => $s['course']->id, 'student_id' => $other->id, 'status' => 'active',
        ]);

        $data = $this->actingAs($other)->getJson("/api/quizzes/{$s['quiz']->id}")->json('data');

        $this->assertArrayHasKey('attempts', $data);
        $this->assertSame([], $data['attempts']);
    }

    // ----------------------------------------------------- the review endpoint

    public function test_a_student_can_review_their_own_completed_attempt(): void
    {
        $s = $this->scenario();
        $this->start($s);
        $this->submit($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $response = $this->actingAs($s['student'])
            ->getJson("/api/quiz-attempts/{$this->attemptId($s)}");

        $response->assertOk();
        $this->assertSame('completed', $response->json('attempt.status'));
        $this->assertCount(2, $response->json('questions'));
        $this->assertTrue($response->json('questions.0.is_correct'));
        $this->assertSame(
            $s['correctOption']->id,
            $response->json('questions.0.submitted_answer'),
        );
    }

    public function test_a_student_can_review_their_own_expired_attempt(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);
        $this->start($s);
        $s['attemptId'] = $this->attemptId($s);
        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $this->travel(31)->minutes();
        $this->start($s);

        $response = $this->actingAs($s['student'])
            ->getJson("/api/quiz-attempts/{$s['attemptId']}");

        $response->assertOk();
        $this->assertSame('expired', $response->json('attempt.status'));
    }

    public function test_an_in_progress_attempt_is_not_reviewable_via_the_endpoint(): void
    {
        $s = $this->scenario();
        $this->start($s);

        $this->actingAs($s['student'])
            ->getJson("/api/quiz-attempts/{$this->attemptId($s)}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('attempt');
    }

    public function test_reviewing_someone_elses_attempt_is_forbidden(): void
    {
        $s = $this->scenario();
        $this->start($s);
        $this->submit($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $other = User::factory()->student()->create();

        $this->actingAs($other)
            ->getJson("/api/quiz-attempts/{$this->attemptId($s)}")
            ->assertForbidden();
    }

    public function test_the_course_owner_can_review_a_students_attempt(): void
    {
        $s = $this->scenario();
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        $this->start($s);
        $this->submit($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $this->actingAs($owner)
            ->getJson("/api/quiz-attempts/{$this->attemptId($s)}")
            ->assertOk();
    }

    // --------------------------------------------------------- guard rails

    public function test_a_review_shows_only_the_paper_the_student_was_served(): void
    {
        // A fixed quiz can grow after a student has finished: adding a question
        // is allowed, only editing or deleting one that was served is not. The
        // review must still describe the paper that was sat, not the quiz as it
        // stands today.
        $s = $this->scenario();
        $this->start($s);
        $this->submit($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        QuizQuestion::factory()->create([
            'quiz_id' => $s['quiz']->id, 'type' => 'multiple_choice', 'points' => 1, 'sort_order' => 3,
        ]);

        $response = $this->actingAs($s['student'])
            ->getJson("/api/quiz-attempts/{$this->attemptId($s)}");

        $response->assertOk();
        $this->assertCount(2, $response->json('questions'));
        $this->assertTrue($response->json('questions.0.is_correct'));
        $this->assertContains(
            $s['correct']->id,
            collect($response->json('questions'))->pluck('id')->all(),
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array{timeLimit?: int|null, attemptsAllowed?: int}  $overrides
     */
    private function scenario(array $overrides = []): array
    {
        $student = User::factory()->student()->create();
        $course = Course::factory()->create();
        $quiz = Quiz::factory()->create([
            'course_id' => $course->id,
            'passing_score' => 50,
            'attempts_allowed' => $overrides['attemptsAllowed'] ?? 0,
            'time_limit_minutes' => $overrides['timeLimit'] ?? null,
            'status' => 'active',
        ]);

        $correct = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id, 'type' => 'multiple_choice', 'points' => 1, 'sort_order' => 1,
        ]);
        $correctOption = QuizOption::factory()->create(['quiz_question_id' => $correct->id, 'is_correct' => true]);
        QuizOption::factory()->create(['quiz_question_id' => $correct->id, 'is_correct' => false]);

        QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id, 'type' => 'short_answer', 'points' => 0.5, 'sort_order' => 2,
        ]);

        Enrollment::factory()->create([
            'course_id' => $course->id, 'student_id' => $student->id, 'status' => 'active',
        ]);

        return [
            'student' => $student,
            'course' => $course,
            'quiz' => $quiz,
            'correct' => $correct,
            'correctOption' => $correctOption,
        ];
    }

    private function attemptId(array $s): int
    {
        return QuizAttempt::where('quiz_id', $s['quiz']->id)
            ->where('student_id', $s['student']->id)
            ->latest('id')
            ->value('id');
    }

    private function overview(array $s)
    {
        return $this->actingAs($s['student'])->getJson("/api/quizzes/{$s['quiz']->id}");
    }

    private function start(array $s)
    {
        return $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
    }

    /**
     * @param  array<int, array{question_id: int, answer: mixed}>  $answers
     */
    private function saveAnswers(array $s, array $answers)
    {
        return $this->actingAs($s['student'])
            ->patchJson("/api/quiz-attempts/{$this->attemptId($s)}/answers", ['answers' => $answers]);
    }

    /**
     * @param  array<int, array{question_id: int, answer: mixed}>  $answers
     */
    private function submit(array $s, array $answers)
    {
        return $this->actingAs($s['student'])
            ->postJson("/api/quiz-attempts/{$this->attemptId($s)}/submit", ['questions' => $answers]);
    }
}
