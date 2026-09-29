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
 * Late rules: a quiz can grant a post-deadline grace (`late_grace_minutes`)
 * during which the student may still finish and hand in.
 *
 * The strict deadline keeps its meaning -- it is the clock the student races,
 * shown in the countdown. The grace puts a second, later moment (`answerableUntil`
 * = strict deadline + grace) at which the attempt is force-closed. Between the
 * two a save is still accepted and a submission is graded normally but marked
 * `submitted_late`; past the cutoff the attempt expires exactly as it would
 * without any grace. A quiz with no grace behaves precisely as before.
 */
class QuizLateGraceTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ authoring

    public function test_an_instructor_can_set_a_late_grace_and_clear_it(): void
    {
        $s = $this->scenario();
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson($this->base($s, '?_method=PUT'), [
                'title' => 'Exam',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'late_grace_minutes' => 10,
            ])
            ->assertOk();

        $this->assertSame(10, $s['quiz']->fresh()->late_grace_minutes);

        // An empty string means "no grace", not a permanent cut-off.
        $this->actingAs($owner)
            ->postJson($this->base($s, '?_method=PUT'), [
                'title' => 'Exam',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'late_grace_minutes' => '',
            ])
            ->assertOk();

        $this->assertNull($s['quiz']->fresh()->late_grace_minutes);
    }

    public function test_late_grace_must_be_a_whole_number(): void
    {
        $s = $this->scenario();
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson($this->base($s, '?_method=PUT'), [
                'title' => 'Exam',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'late_grace_minutes' => 'soon',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('late_grace_minutes');
    }

    // ------------------------------------------------------------ the grace

    public function test_a_save_inside_the_grace_is_accepted(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'lateGrace' => 10]);
        $question = $this->mc($s['quiz']);

        $this->start($s);
        $attemptId = $this->attemptId($s);

        // The strict clock has run out but the grace has not.
        $this->travel(31)->minutes();

        $this->actingAs($s['student'])
            ->patchJson("/api/quiz-attempts/{$attemptId}/answers", [
                'answers' => [['question_id' => $question->id, 'answer' => $this->correctOption($question)->id]],
            ])
            ->assertNoContent();

        $this->assertFalse(QuizAttempt::findOrFail($attemptId)->isGraded());
    }

    public function test_a_submission_inside_the_grace_is_graded_late(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'lateGrace' => 10]);
        $question = $this->mc($s['quiz']);

        $this->start($s);
        $attemptId = $this->attemptId($s);

        $this->actingAs($s['student'])
            ->patchJson("/api/quiz-attempts/{$attemptId}/answers", [
                'answers' => [['question_id' => $question->id, 'answer' => $this->correctOption($question)->id]],
            ])
            ->assertNoContent();

        $this->travel(31)->minutes();

        $response = $this->submit($s);
        $response->assertOk();
        $this->assertSame('completed', $response->json('attempt.status'));
        $this->assertTrue($response->json('attempt.late'));

        $attempt = QuizAttempt::findOrFail($attemptId);
        $this->assertSame('completed', $attempt->status->value);
        $this->assertTrue($attempt->submitted_late);
        $this->assertSame(100.0, (float) $attempt->score_percentage);
    }

    public function test_a_submission_on_time_is_not_late(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'lateGrace' => 10]);
        $this->mc($s['quiz']);

        $this->start($s);

        $response = $this->submit($s);
        $response->assertOk();
        $this->assertFalse($response->json('attempt.late'));

        $this->assertFalse(QuizAttempt::findOrFail($this->attemptId($s))->submitted_late);
    }

    public function test_a_save_after_the_grace_cutoff_is_rejected_and_closes_the_attempt(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'lateGrace' => 10]);
        $question = $this->mc($s['quiz']);

        $this->start($s);
        $attemptId = $this->attemptId($s);

        $this->actingAs($s['student'])
            ->patchJson("/api/quiz-attempts/{$attemptId}/answers", [
                'answers' => [['question_id' => $question->id, 'answer' => $this->correctOption($question)->id]],
            ])
            ->assertNoContent();

        $this->travel(41)->minutes();

        $this->actingAs($s['student'])
            ->patchJson("/api/quiz-attempts/{$attemptId}/answers", [
                'answers' => [['question_id' => $question->id, 'answer' => $this->correctOption($question)->id]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attempt');

        $attempt = QuizAttempt::findOrFail($attemptId);
        $this->assertTrue($attempt->isGraded());
        $this->assertSame('expired', $attempt->status->value);
        $this->assertFalse($attempt->submitted_late);
        $this->assertSame(100.0, (float) $attempt->score_percentage);
    }

    public function test_a_resume_inside_the_grace_hands_back_the_attempt(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'lateGrace' => 10, 'attemptsAllowed' => 1]);
        $this->mc($s['quiz']);

        $this->start($s);
        $attemptId = $this->attemptId($s);

        $this->travel(31)->minutes();

        $response = $this->start($s);
        $response->assertOk();
        $this->assertSame($attemptId, (int) $response->json('attempt.id'));
        $this->assertNotNull($response->json('grace_until'));
    }

    public function test_a_new_attempt_is_refused_once_the_grace_expires(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'lateGrace' => 10, 'attemptsAllowed' => 1]);
        $this->mc($s['quiz']);

        $this->start($s);
        $attemptId = $this->attemptId($s);

        $this->travel(41)->minutes();

        $this->start($s)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attempts');

        // The expired paper was still closed and its saved work graded.
        $this->assertSame('expired', QuizAttempt::findOrFail($attemptId)->status->value);
    }

    // -------------------------------------------------------------- visibility

    public function test_the_overview_offers_the_attempt_while_it_is_in_grace(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'lateGrace' => 10, 'attemptsAllowed' => 1]);
        $this->mc($s['quiz']);

        $this->start($s);

        $this->travel(31)->minutes();

        $this->actingAs($s['student'])
            ->getJson("/api/quizzes/{$s['quiz']->id}")
            ->assertOk()
            ->assertJsonPath('data.late_grace_minutes', 10)
            ->assertJsonPath('data.live_attempt.id', $this->attemptId($s))
            // The overview's "closes at" reads the grace cut-off, not the strict
            // deadline, so an attempt in grace is not shown as closing in the past.
            ->assertJsonPath('data.live_attempt.grace_until', $s['quiz']->fresh()->graceDeadlineFor(QuizAttempt::findOrFail($this->attemptId($s)))->toISOString());

        $this->travel(11)->minutes();

        $this->actingAs($s['student'])
            ->getJson("/api/quizzes/{$s['quiz']->id}")
            ->assertOk()
            ->assertJsonPath('data.live_attempt', null);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array{timeLimit?: int|null, lateGrace?: int|null, attemptsAllowed?: int}  $overrides
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
            'late_grace_minutes' => $overrides['lateGrace'] ?? null,
            'status' => 'active',
        ]);

        Enrollment::factory()->create([
            'course_id' => $course->id, 'student_id' => $student->id, 'status' => 'active',
        ]);

        return [
            'student' => $student,
            'course' => $course,
            'quiz' => $quiz,
        ];
    }

    private function base(array $s, string $suffix = ''): string
    {
        return "/api/instructor/courses/{$s['course']->slug}/quizzes/{$s['quiz']->id}{$suffix}";
    }

    private function mc(Quiz $quiz): QuizQuestion
    {
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'type' => 'multiple_choice',
            'points' => 1,
            'sort_order' => 1,
        ]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => false]);

        return $question;
    }

    private function correctOption(QuizQuestion $question): QuizOption
    {
        return $question->options()->where('is_correct', true)->firstOrFail();
    }

    private function attemptId(array $s): int
    {
        return QuizAttempt::where('quiz_id', $s['quiz']->id)
            ->where('student_id', $s['student']->id)
            ->latest('id')
            ->value('id');
    }

    private function start(array $s)
    {
        return $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
    }

    private function submit(array $s)
    {
        $attemptId = $this->attemptId($s);
        $questions = $s['quiz']->questions()->with('options')->get()
            ->map(fn (QuizQuestion $q): array => [
                'question_id' => $q->id,
                'answer' => $q->options()->where('is_correct', true)->value('id'),
            ])
            ->all();

        return $this->actingAs($s['student'])
            ->postJson("/api/quiz-attempts/{$attemptId}/submit", ['questions' => $questions]);
    }
}
