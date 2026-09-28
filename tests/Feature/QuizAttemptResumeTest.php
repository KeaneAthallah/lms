<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autosave, resume, and what a time limit actually does.
 *
 * These are the first tests in the suite to touch `time_limit_minutes` at all.
 * Before this the whole expiry path was covered by nothing, and the reason it
 * was wrong is a good description of the failure mode being fixed here: a
 * student whose timer ran out was bounced back to the overview with their
 * answers thrown away, the attempt was left `in_progress` forever, and because
 * only `completed` attempts counted, Start happily issued a fresh full-length
 * paper. Repeat until it passed.
 */
class QuizAttemptResumeTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ resume

    public function test_a_second_start_resumes_the_open_attempt(): void
    {
        $s = $this->scenario();

        $first = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $first->assertCreated();

        $second = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $second->assertOk();
        $this->assertTrue($second->json('resumed'));
        $this->assertSame(
            $first->json('attempt.id'),
            $second->json('attempt.id'),
            'Starting again must not mint a second paper for an attempt still open.',
        );
        $this->assertDatabaseCount('quiz_attempts', 1);
    }

    public function test_a_resumed_attempt_gets_the_same_questions_in_the_same_order(): void
    {
        $s = $this->scenario();

        $first = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $second = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->assertSame(
            collect($first->json('questions'))->pluck('id')->all(),
            collect($second->json('questions'))->pluck('id')->all(),
        );
    }

    public function test_a_resumed_attempt_opens_with_the_saved_answers(): void
    {
        $s = $this->scenario();

        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [
            ['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id],
            ['question_id' => $s['short']->id, 'answer' => 'photosynthesis'],
        ]);

        $answers = $this->actingAs($s['student'])
            ->postJson("/api/quizzes/{$s['quiz']->id}/start")
            ->json('answers');

        $this->assertSame($s['correctOption']->id, $answers[$s['correct']->id]);
        $this->assertSame('photosynthesis', $answers[$s['short']->id]);
    }

    public function test_a_saved_multi_select_comes_back_as_a_list_not_json_text(): void
    {
        // The client holds a real array, so a draft that came back as the raw
        // stored string would render as a blank box and read as unanswered.
        $s = $this->scenario(['withMultiSelect' => true]);

        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [
            ['question_id' => $s['multiSelect']->id, 'answer' => $s['multiCorrect']->pluck('id')->all()],
        ]);

        $answers = $this->actingAs($s['student'])
            ->postJson("/api/quizzes/{$s['quiz']->id}/start")
            ->json('answers');

        $this->assertIsArray($answers[$s['multiSelect']->id]);
        $this->assertEqualsCanonicalizing(
            $s['multiCorrect']->pluck('id')->all(),
            $answers[$s['multiSelect']->id],
        );
    }

    public function test_a_cleared_answer_resumes_as_unanswered_and_not_as_option_zero(): void
    {
        // The regression this guards: `serialize(null)` used to cast to `'0'`,
        // which is a real option id, so a student who cleared a question came
        // back to it looking answered.
        $s = $this->scenario();

        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);
        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => null]]);

        $this->assertDatabaseHas('quiz_answers', [
            'quiz_attempt_id' => $this->attemptId($s),
            'quiz_question_id' => $s['correct']->id,
            'answer' => '',
        ]);

        $answers = $this->actingAs($s['student'])
            ->postJson("/api/quizzes/{$s['quiz']->id}/start")
            ->json('answers');

        $this->assertArrayHasKey((string) $s['correct']->id, $answers);
        $this->assertNull($answers[$s['correct']->id]);
    }

    public function test_an_unanswered_choice_is_stored_as_blank_and_not_as_option_zero(): void
    {
        $s = $this->scenario();

        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->submit($s, []);

        $this->assertDatabaseHas('quiz_answers', [
            'quiz_attempt_id' => $this->attemptId($s),
            'quiz_question_id' => $s['correct']->id,
            'answer' => '',
        ]);
    }

    public function test_resuming_does_not_hand_back_the_time_already_used(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);

        $first = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->travel(20)->minutes();

        $second = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->assertSame(
            $first->json('expires_at'),
            $second->json('expires_at'),
            'The clock runs from when the student started, not from when they came back.',
        );

        $this->travel(11)->minutes();

        $after = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->assertFalse($after->json('resumed'), 'Past the deadline there is nothing left to resume.');
        $this->assertNotSame($first->json('attempt.id'), $after->json('attempt.id'));
    }

    public function test_the_overview_offers_a_resume_when_one_is_open(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $live = $this->actingAs($s['student'])->getJson("/api/quizzes/{$s['quiz']->id}")->json('data.live_attempt');

        $this->assertNotNull($live);
        $this->assertNotNull($live['expires_at']);
    }

    public function test_the_overview_does_not_offer_a_resume_for_an_attempt_past_its_deadline(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->travel(31)->minutes();

        $this->assertNull(
            $this->actingAs($s['student'])->getJson("/api/quizzes/{$s['quiz']->id}")->json('data.live_attempt'),
        );
    }

    public function test_an_untimed_quiz_stays_resumable_forever(): void
    {
        $s = $this->scenario();

        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->travel(30)->days();

        $this->assertNotNull(
            $this->actingAs($s['student'])->getJson("/api/quizzes/{$s['quiz']->id}")->json('data.live_attempt'),
        );
    }

    // ---------------------------------------------------------------- autosave

    public function test_a_saved_answer_is_stored_ungraded_until_submission(): void
    {
        $s = $this->scenario();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $answer = QuizAnswer::where('quiz_attempt_id', $this->attemptId($s))
            ->where('quiz_question_id', $s['correct']->id)
            ->sole();

        $this->assertNull($answer->is_correct, 'A draft has no verdict on it.');
        $this->assertNull($answer->points_earned, 'A graded zero is not the same as no grade.');
    }

    public function test_a_save_ignores_a_question_outside_the_frozen_paper(): void
    {
        $s = $this->scenario();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        // A question the student was never served. Storing it would create a row
        // grading never reads, and rejecting the whole save would cost them the
        // answers they were actually trying to keep.
        $stranger = QuizQuestion::factory()->create(['quiz_id' => $s['quiz']->id, 'type' => 'multiple_choice']);

        $this->saveAnswers($s, [
            ['question_id' => $stranger->id, 'answer' => 1],
            ['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id],
        ])->assertNoContent();

        $this->assertDatabaseMissing('quiz_answers', ['quiz_question_id' => $stranger->id]);
        $this->assertDatabaseHas('quiz_answers', [
            'quiz_attempt_id' => $this->attemptId($s),
            'quiz_question_id' => $s['correct']->id,
        ]);
    }

    public function test_a_save_is_idempotent(): void
    {
        // The client retries a debounced save it never saw the response to, so
        // the same answer landing twice has to leave one row, not two.
        $s = $this->scenario();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $payload = ['answers' => [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]];
        $this->saveAnswers($s, $payload['answers']);
        $this->saveAnswers($s, $payload['answers']);

        $this->assertSame(1, QuizAnswer::where('quiz_attempt_id', $this->attemptId($s))->count());
    }

    public function test_a_save_is_rejected_once_the_attempt_is_submitted(): void
    {
        $s = $this->scenario();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->submit($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        // A debounced save racing the final submit. The lock stops it blanking
        // the graded answer, and the client shows this as "not saved" rather
        // than treating it as the failure it is not.
        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]])
            ->assertStatus(422);

        $this->assertSame(
            $s['correctOption']->id,
            (int) QuizAnswer::where('quiz_attempt_id', $this->attemptId($s))
                ->where('quiz_question_id', $s['correct']->id)
                ->sole()
                ->answer,
        );
    }

    public function test_a_save_cannot_touch_another_students_attempt(): void
    {
        $s = $this->scenario();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->actingAs(User::factory()->student()->create())
            ->patchJson("/api/quiz-attempts/{$this->attemptId($s)}/answers", [
                'answers' => [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]],
            ])
            ->assertForbidden();
    }

    public function test_a_save_without_a_question_id_is_rejected(): void
    {
        $s = $this->scenario();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->saveAnswers($s, [['answer' => 3]])->assertStatus(422);
    }

    // ------------------------------------------------------ submit / autosave

    public function test_a_submit_scores_a_question_the_client_never_sent_from_the_draft(): void
    {
        // The keystroke that lands a second before Submit is still debounced
        // client-side. Grading the payload alone would score the older copy.
        $s = $this->scenario();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $response = $this->submit($s, []);

        $this->assertSame(1.0, (float) $response->json('attempt.score'));
    }

    public function test_a_submit_keeps_an_explicitly_cleared_answer_over_the_saved_draft(): void
    {
        $s = $this->scenario();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        // The student went back and cleared it. Falling back to the draft here
        // would hand back the answer they just deleted.
        $response = $this->submit($s, [['question_id' => $s['correct']->id, 'answer' => null]]);

        $this->assertSame(0.0, (float) $response->json('attempt.score'));
    }

    public function test_a_quiz_survives_a_refresh_unchanged(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);

        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [
            ['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id],
            ['question_id' => $s['short']->id, 'answer' => 'photosynthesis'],
        ]);

        // Close the tab, come back, resume, submit. The score has to be the one
        // the uninterrupted student would have got.
        $resumed = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $response = $this->submit($s, collect($resumed->json('answers'))->map(fn ($answer, $id) => [
            'question_id' => $id,
            'answer' => $answer,
        ])->values()->all());

        $this->assertSame(1.5, (float) $response->json('attempt.score'));
        $this->assertTrue($response->json('attempt.passed'));
    }

    // ------------------------------------------------------------- the time limit

    public function test_a_submit_past_the_deadline_is_scored_from_what_was_saved(): void
    {
        // The headline change. This used to throw, grade nothing and keep the
        // attempt open, so the student lost the time they had actually spent.
        $s = $this->scenario(['timeLimit' => 30]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [
            ['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id],
            ['question_id' => $s['short']->id, 'answer' => 'photosynthesis'],
        ]);

        $this->travel(31)->minutes();

        $response = $this->submit($s, []);
        $response->assertOk();

        $this->assertSame(1.5, (float) $response->json('attempt.score'));
        $this->assertSame('expired', $response->json('attempt.status'));
    }

    public function test_a_save_past_the_deadline_closes_the_attempt_and_scores_it(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $this->travel(31)->minutes();

        $this->saveAnswers($s, [['question_id' => $s['short']->id, 'answer' => 'x']])->assertStatus(422);

        $attempt = QuizAttempt::find($this->attemptId($s));
        $this->assertSame('expired', $attempt->status->value);
        $this->assertNotNull($attempt->submitted_at);
        $this->assertSame(1.0, (float) $attempt->score);
    }

    public function test_starting_after_the_deadline_closes_the_old_attempt_and_issues_a_new_one(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'attemptsAllowed' => 0]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id]]);

        $this->travel(31)->minutes();

        $second = $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $second->assertCreated();
        $this->assertFalse($second->json('resumed'));

        $this->assertSame(2, QuizAttempt::count());
        $this->assertSame('expired', QuizAttempt::orderBy('id')->first()->status->value);
    }

    public function test_a_timed_quiz_cannot_be_retaken_by_walking_away_from_it(): void
    {
        // The hole, written as a test: one attempt allowed, let the clock run
        // out, ask again.
        $s = $this->scenario(['timeLimit' => 30, 'attemptsAllowed' => 1]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->travel(31)->minutes();

        $this->actingAs($s['student'])
            ->postJson("/api/quizzes/{$s['quiz']->id}/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors('attempts');
    }

    public function test_an_expired_attempt_counts_towards_the_limit(): void
    {
        $s = $this->scenario(['timeLimit' => 30, 'attemptsAllowed' => 1]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->travel(31)->minutes();

        $this->assertSame(1, $s['quiz']->fresh()->attemptsFor($s['student']));
        $this->assertSame(
            1,
            $this->actingAs($s['student'])->getJson("/api/quizzes/{$s['quiz']->id}")->json('data.attempts_used'),
        );
    }

    public function test_an_expired_attempt_keeps_its_score_out_of_the_gradebook(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [
            ['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id],
            ['question_id' => $s['short']->id, 'answer' => 'photosynthesis'],
        ]);

        $this->travel(31)->minutes();
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");

        $this->assertDatabaseHas('grades', [
            'student_id' => $s['student']->id,
            'source_type' => QuizAttempt::class,
            'score' => 1.5,
        ]);
    }

    public function test_a_timed_out_attempt_still_counts_for_the_best_score(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->saveAnswers($s, [
            ['question_id' => $s['correct']->id, 'answer' => $s['correctOption']->id],
            ['question_id' => $s['short']->id, 'answer' => 'photosynthesis'],
        ]);
        $this->travel(31)->minutes();
        $this->submit($s, []);

        // The student never clicked submit. Their work should not vanish from
        // the overview because the browser tab was the thing that closed.
        $this->assertSame(
            100.0,
            (float) $this->actingAs($s['student'])
                ->getJson("/api/quizzes/{$s['quiz']->id}")
                ->json('data.best_score'),
        );
    }

    public function test_a_submitted_attempt_cannot_be_submitted_again_after_expiry(): void
    {
        $s = $this->scenario(['timeLimit' => 30]);
        $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
        $this->submit($s, []);

        $this->travel(31)->minutes();

        // `submitted_at`, not the status: an expired attempt is graded, so a
        // status check would wave a second submit straight through.
        $this->submit($s, [])->assertStatus(422)->assertJsonValidationErrors('attempt');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A student enrolled in a course with one two-question quiz: a choice worth
     * 1 and a short answer worth 0.5, so a wrong answer is visible as a
     * half-point rather than hiding inside a percentage.
     *
     * @param  array{timeLimit?: int|null, attemptsAllowed?: int, withMultiSelect?: bool}  $overrides
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

        $short = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id, 'type' => 'short_answer', 'points' => 0.5, 'sort_order' => 2,
        ]);
        QuizOption::factory()->create([
            'quiz_question_id' => $short->id, 'option_text' => 'photosynthesis', 'is_correct' => true,
        ]);

        Enrollment::factory()->create([
            'course_id' => $course->id, 'student_id' => $student->id, 'status' => 'active',
        ]);

        $extra = [];

        if ($overrides['withMultiSelect'] ?? false) {
            $multi = QuizQuestion::factory()->create([
                'quiz_id' => $quiz->id, 'type' => 'multi_select', 'points' => 1, 'sort_order' => 3,
            ]);
            $multiCorrect = QuizOption::factory()->count(2)->create([
                'quiz_question_id' => $multi->id, 'is_correct' => true,
            ]);
            QuizOption::factory()->create(['quiz_question_id' => $multi->id, 'is_correct' => false]);
            $extra = ['multiSelect' => $multi, 'multiCorrect' => $multiCorrect];
        }

        return array_merge([
            'student' => $student,
            'course' => $course,
            'quiz' => $quiz,
            'correct' => $correct,
            'correctOption' => $correctOption,
            'short' => $short,
        ], $extra);
    }

    private function attemptId(array $s): int
    {
        return QuizAttempt::where('quiz_id', $s['quiz']->id)
            ->where('student_id', $s['student']->id)
            ->latest('id')
            ->value('id');
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
