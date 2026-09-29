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
 * Exam windows: an availability window (`available_from` ... `available_until`)
 * bounds when a quiz can be sat.
 *
 * The window is a gate on *new* attempts and a hard deadline for attempts
 * already in flight. `deadlineFor()` is the one place a deadline is worked out,
 * so clamping it with the window's close is all it takes for an untimed attempt
 * to be graded the moment the window ends -- the same close-what-expired path
 * that handles a timed attempt running out. A quiz without a window behaves
 * exactly as it always did.
 */
class QuizExamWindowTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ authoring

    public function test_an_instructor_can_save_an_availability_window_and_clear_it(): void
    {
        $s = $this->scenario();
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson($this->base($s, '?_method=PUT'), [
                'title' => 'Exam',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'available_from' => '2026-10-05T09:00',
                'available_until' => '2026-10-05T15:00',
            ])
            ->assertOk();

        $this->assertSame('2026-10-05T09:00', $s['quiz']->fresh()->available_from?->format('Y-m-d\TH:i'));
        $this->assertSame('2026-10-05T15:00', $s['quiz']->fresh()->available_until?->format('Y-m-d\TH:i'));

        // Empty strings mean "no window" again, not a permanently closed quiz.
        $this->actingAs($owner)
            ->postJson($this->base($s, '?_method=PUT'), [
                'title' => 'Exam',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'available_from' => '',
                'available_until' => '',
            ])
            ->assertOk();

        $fresh = $s['quiz']->fresh();
        $this->assertNull($fresh->available_from);
        $this->assertNull($fresh->available_until);
    }

    public function test_available_until_is_refused_before_available_from(): void
    {
        $s = $this->scenario();
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson($this->base($s, '?_method=PUT'), [
                'title' => 'Exam',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'available_from' => '2026-10-05T15:00',
                'available_until' => '2026-10-05T09:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('available_until');
    }

    // ------------------------------------------------------------ gating

    public function test_start_is_refused_before_the_window_opens(): void
    {
        $s = $this->scenario();
        $s['quiz']->update(['available_from' => now()->addMinutes(5)]);

        $this->start($s)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attempts')
            ->assertJsonPath('errors.attempts.0', fn (string $message): bool => str_contains($message, 'This quiz opens on'));
    }

    public function test_start_is_refused_after_the_window_closes(): void
    {
        $s = $this->scenario();
        $s['quiz']->update(['available_until' => now()->subMinutes(5)]);

        $this->start($s)
            ->assertUnprocessable()
            ->assertJsonPath('errors.attempts.0', fn (string $message): bool => str_contains($message, 'This quiz closed on'));
    }

    public function test_start_succeeds_inside_the_window(): void
    {
        $s = $this->scenario();
        $this->mc($s['quiz']);
        $s['quiz']->update([
            'available_from' => now()->subHour(),
            'available_until' => now()->addHour(),
        ]);

        $this->start($s)->assertSuccessful();
    }

    // -------------------------------------------------- closing in-flight work

    public function test_an_untimed_attempt_in_flight_is_closed_when_the_window_ends(): void
    {
        $s = $this->scenario();
        $question = $this->mc($s['quiz']);
        $s['quiz']->update(['available_until' => now()->addMinutes(30)]);

        $this->start($s);
        $attemptId = $this->attemptId($s);

        // The student answers (autosaves) one question before the window closes.
        $this->actingAs($s['student'])
            ->patchJson("/api/quiz-attempts/{$attemptId}/answers", [
                'answers' => [['question_id' => $question->id, 'answer' => $this->correctOption($question)->id]],
            ])
            ->assertNoContent();

        // Nobody is watching as the window passes; the student tries to sit again.
        $this->travel(31)->minutes();

        $this->start($s)
            ->assertUnprocessable()
            ->assertJsonPath('errors.attempts.0', fn (string $message): bool => str_contains($message, 'This quiz closed on'));

        $attempt = QuizAttempt::findOrFail($attemptId);
        $this->assertTrue($attempt->isGraded());
        $this->assertSame('expired', $attempt->status->value);
        $this->assertSame(1.0, (float) $attempt->answers()->where('quiz_question_id', $question->id)->value('points_earned'));
    }

    public function test_a_save_after_the_window_closes_is_rejected_and_closes_the_attempt(): void
    {
        $s = $this->scenario();
        $question = $this->mc($s['quiz']);
        $s['quiz']->update(['available_until' => now()->addMinutes(30)]);

        $this->start($s);
        $attemptId = $this->attemptId($s);

        $this->travel(31)->minutes();

        $this->actingAs($s['student'])
            ->patchJson("/api/quiz-attempts/{$attemptId}/answers", [
                'answers' => [['question_id' => $question->id, 'answer' => $this->correctOption($question)->id]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attempt');

        $this->assertTrue(QuizAttempt::findOrFail($attemptId)->isGraded());
    }

    // -------------------------------------------------------------- visibility

    public function test_the_overview_tells_a_student_where_the_window_stands(): void
    {
        $s = $this->scenario();
        $s['quiz']->update([
            'available_from' => now()->subHour(),
            'available_until' => now()->addHour(),
        ]);

        $this->actingAs($s['student'])
            ->getJson("/api/quizzes/{$s['quiz']->id}")
            ->assertOk()
            ->assertJsonPath('data.availability', 'open')
            ->assertJsonPath('data.available_from', $s['quiz']->fresh()->available_from->toISOString())
            ->assertJsonPath('data.available_until', $s['quiz']->fresh()->available_until->toISOString());

        $this->travel(61)->minutes();

        $this->actingAs($s['student'])
            ->getJson("/api/quizzes/{$s['quiz']->id}")
            ->assertOk()
            ->assertJsonPath('data.availability', 'closed');
    }

    public function test_a_quiz_without_a_window_is_always_open(): void
    {
        $s = $this->scenario();

        $this->actingAs($s['student'])
            ->getJson("/api/quizzes/{$s['quiz']->id}")
            ->assertOk()
            ->assertJsonPath('data.availability', 'open');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array{timeLimit?: int|null}  $overrides
     */
    private function scenario(array $overrides = []): array
    {
        $student = User::factory()->student()->create();
        $course = Course::factory()->create();
        $quiz = Quiz::factory()->create([
            'course_id' => $course->id,
            'passing_score' => 50,
            'attempts_allowed' => 0,
            'time_limit_minutes' => $overrides['timeLimit'] ?? null,
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
}
