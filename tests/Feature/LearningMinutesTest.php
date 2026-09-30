<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the definition of a learning minute, which two endpoints report under
 * different names: `learning_minutes_7d` on /api/learning-insights and
 * `learning_minutes_total` on /api/portfolio.
 *
 * Every test here is characterisation. They are expected to pass both before
 * and after any refactor of that definition -- that is the point. A test that
 * only passes afterwards is testing the refactor, not the rule, and a change
 * that moves any of these numbers has changed what the platform tells a
 * student about their own time.
 */
class LearningMinutesTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(int $durationSeconds, ?Course $course = null): Lesson
    {
        $course ??= Course::factory()->create();

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => CourseSection::factory()->create(['course_id' => $course->id])->id,
            'is_published' => true,
        ]);

        $lesson->forceFill(['duration_seconds' => $durationSeconds])->save();

        return $lesson;
    }

    private function complete(User $student, Lesson $lesson, mixed $completedAt = null): void
    {
        LessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $lesson->course_id,
            'lesson_id' => $lesson->id,
            'progress_percent' => 100,
            'completed_at' => $completedAt ?? now(),
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function attempt(User $student, mixed $startedAt, mixed $submittedAt, array $overrides = []): QuizAttempt
    {
        $quiz = Quiz::factory()->create(['course_id' => Course::factory()->create()->id]);

        return QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
            ...$overrides,
        ]);
    }

    private function minutes7d(User $student): int
    {
        return (int) $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->json('data.summary.learning_minutes_7d');
    }

    private function minutesTotal(User $student): int
    {
        return (int) $this->actingAs($student)
            ->getJson('/api/portfolio')
            ->assertOk()
            ->json('data.summary.learning_minutes_total');
    }

    public function test_a_lesson_completed_inside_the_window_contributes_its_duration(): void
    {
        $student = User::factory()->student()->create();
        $this->complete($student, $this->lesson(600), now()->subDays(6));

        $this->assertSame(10, $this->minutes7d($student));
        $this->assertSame(10, $this->minutesTotal($student));
    }

    public function test_the_two_endpoints_differ_only_by_the_window(): void
    {
        $student = User::factory()->student()->create();
        $this->complete($student, $this->lesson(600), now()->subDays(8));
        $this->complete($student, $this->lesson(300), now()->subDay());

        // The old completion is outside seven days and inside all time. This is
        // the whole difference between the two methods: same arithmetic, one
        // carries a cutoff. If these two ever disagree about the old row, one of
        // them has grown its own idea of the window.
        $this->assertSame(5, $this->minutes7d($student));
        $this->assertSame(15, $this->minutesTotal($student));
    }

    public function test_a_lesson_duration_is_rounded_up_to_the_next_whole_minute(): void
    {
        $student = User::factory()->student()->create();
        $this->complete($student, $this->lesson(620));

        // 620s is 10m20s. The accumulators ceil it to 11. `estimatedMinutes()`,
        // which decides what a single lesson card *displays*, rounds the same
        // number to 10 -- a known, deliberate disagreement (see the audit's
        // residual-risk note) that this test deliberately does not resolve.
        $this->assertSame(11, $this->minutesTotal($student));
    }

    public function test_a_lesson_with_no_recorded_duration_contributes_nothing(): void
    {
        $student = User::factory()->student()->create();
        $this->complete($student, $this->lesson(0));

        $this->assertSame(0, $this->minutesTotal($student));
    }

    public function test_an_attempt_contributes_its_elapsed_time_rounded_up(): void
    {
        $student = User::factory()->student()->create();
        $this->attempt($student, now()->subSeconds(90), now());

        // 90s is 1m30s, so 2. A lesson the student never opened contributes
        // nothing here, which is the "no fabricated statistics" rule holding.
        $this->assertSame(2, $this->minutesTotal($student));
    }

    public function test_a_single_attempt_is_capped_at_two_hours(): void
    {
        $student = User::factory()->student()->create();
        $this->attempt($student, now()->subHours(5), now());

        // The cap exists so a forgotten tab left open overnight cannot report a
        // student studying for five hours. It is applied per attempt, not to the
        // total.
        $this->assertSame(120, $this->minutesTotal($student));
    }

    public function test_attempts_are_capped_individually(): void
    {
        $student = User::factory()->student()->create();
        $this->attempt($student, now()->subHours(3), now());
        $this->attempt($student, now()->subHours(3), now());

        // 120 + 120, not 120 for the pair: capping the sum would let a student
        // with two long attempts look identical to one with a single long one.
        $this->assertSame(240, $this->minutesTotal($student));
    }

    public function test_an_attempt_that_ends_before_it_starts_contributes_nothing(): void
    {
        $student = User::factory()->student()->create();
        $this->attempt($student, now(), now()->subHour());

        // Clock skew between the two timestamps must not produce a negative
        // number of minutes, or a total that is quietly too low.
        $this->assertSame(0, $this->minutesTotal($student));
    }

    public function test_an_unsubmitted_attempt_contributes_nothing(): void
    {
        $student = User::factory()->student()->create();
        $quiz = Quiz::factory()->create(['course_id' => Course::factory()->create()->id]);

        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'started_at' => now()->subHour(),
            'submitted_at' => null,
        ]);

        // An abandoned attempt has no end, so it has no elapsed time to count.
        $this->assertSame(0, $this->minutesTotal($student));
    }
}
