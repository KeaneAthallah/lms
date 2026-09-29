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
 * Negative marking: a wrong, *attempted* answer deducts a fraction of the
 * question's points.
 *
 * The deduction is a per-question setting (`settings.negative_marking`), works
 * the same way across every scored type, and has three design rules: a blank is
 * never penalised (a student who ran out of time is not charged a second time),
 * a partially correct answer that earns any credit is not penalised, and the
 * per-question loss can show as negative while the attempt total stays at or
 * above zero. Students are told the rule up front, because it changes how their
 * score is decided.
 */
class QuizNegativeMarkingTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ authoring

    public function test_an_instructor_can_set_negative_marking_on_every_question_type(): void
    {
        $s = $this->scenario();
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        foreach ([
            'multiple_choice' => [
                ['option_text' => 'a', 'is_correct' => true],
                ['option_text' => 'b', 'is_correct' => false],
            ],
            'true_false' => [
                ['option_text' => 'True', 'is_correct' => true],
                ['option_text' => 'False', 'is_correct' => false],
            ],
            'short_answer' => [['option_text' => 'Paris', 'is_correct' => true]],
            'multi_select' => [
                ['option_text' => 'a', 'is_correct' => true],
                ['option_text' => 'b', 'is_correct' => true],
            ],
            'numeric' => [],
            'fill_in_blank' => [],
        ] as $type => $options) {
            $settings = ['negative_marking' => 0.25];

            if ($type === 'fill_in_blank') {
                $settings['blanks'] = [1 => ['Laravel']];
            }

            if ($type === 'numeric') {
                $settings['answer'] = 42;
            }

            $text = $type === 'fill_in_blank' ? 'Fill {{1}}?' : "{$type} question?";

            $response = $this->actingAs($owner)->postJson(
                "/api/instructor/courses/{$s['course']->slug}/quizzes/{$s['quiz']->id}/questions",
                [
                    'type' => $type,
                    'question_text' => $text,
                    'points' => 1,
                    'settings' => $settings,
                    'options' => $options,
                ],
            );

            $response->assertCreated();
            $question = QuizQuestion::findOrFail($response->json('question.id'));
            $this->assertEquals(0.25, round($question->settings['negative_marking'] ?? 0.0, 2));
        }
    }

    public function test_zero_negative_marking_is_accepted_and_means_no_penalty(): void
    {
        $s = $this->scenario();
        $question = $this->mc($s['quiz'], points: 1, negative: 0);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => $this->wrongOption($question)->id]]);

        $this->assertSame(0.0, $this->earned($s, $question->id));
        $this->assertSame(0.0, $this->score($s));
    }

    public function test_negative_marking_must_be_a_number(): void
    {
        $s = $this->scenario();
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        $this->actingAs($owner)->postJson(
            "/api/instructor/courses/{$s['course']->slug}/quizzes/{$s['quiz']->id}/questions",
            [
                'type' => 'multiple_choice',
                'question_text' => 'A question?',
                'points' => 1,
                'settings' => ['negative_marking' => 'steep'],
                'options' => [['option_text' => 'a', 'is_correct' => true]],
            ],
        )->assertUnprocessable()->assertJsonValidationErrors('settings.negative_marking');
    }

    public function test_negative_marking_is_clamped_to_zero_through_one(): void
    {
        $s = $this->scenario();
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        foreach ([-0.2, 1.5] as $value) {
            $this->actingAs($owner)->postJson(
                "/api/instructor/courses/{$s['course']->slug}/quizzes/{$s['quiz']->id}/questions",
                [
                    'type' => 'multiple_choice',
                    'question_text' => 'A question?',
                    'points' => 1,
                    'settings' => ['negative_marking' => $value],
                    'options' => [['option_text' => 'a', 'is_correct' => true]],
                ],
            )->assertUnprocessable()->assertJsonValidationErrors('settings.negative_marking');
        }
    }

    // -------------------------------------------------------------- grading

    public function test_a_wrong_attempted_answer_deducts_the_fraction(): void
    {
        $s = $this->scenario();
        $penalised = $this->mc($s['quiz'], points: 1, negative: 0.25);
        $plain = $this->mc($s['quiz'], points: 1, negative: null);
        $this->start($s);

        $this->submit($s, [
            ['question_id' => $penalised->id, 'answer' => $this->wrongOption($penalised)->id],
            ['question_id' => $plain->id, 'answer' => $this->correctOption($plain)->id],
        ]);

        $this->assertSame(-0.25, $this->earned($s, $penalised->id));
        $this->assertSame(1.0, $this->earned($s, $plain->id));
        $this->assertSame(0.75, $this->score($s));
        $this->assertSame(37.5, $this->percentage($s));
    }

    public function test_a_correct_answer_is_never_penalised(): void
    {
        $s = $this->scenario();
        $question = $this->mc($s['quiz'], points: 1, negative: 0.5);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => $this->correctOption($question)->id]]);

        $this->assertSame(1.0, $this->earned($s, $question->id));
        $this->assertSame(1.0, $this->score($s));
    }

    public function test_a_blank_question_is_never_penalised(): void
    {
        $s = $this->scenario();
        $penalised = $this->mc($s['quiz'], points: 1, negative: 0.25);
        $plain = $this->mc($s['quiz'], points: 1, negative: null);
        $this->start($s);

        $this->submit($s, [
            ['question_id' => $plain->id, 'answer' => $this->correctOption($plain)->id],
        ]);

        $this->assertSame(0.0, $this->earned($s, $penalised->id));
        $this->assertSame(1.0, $this->earned($s, $plain->id));
        $this->assertSame(1.0, $this->score($s));
    }

    public function test_free_text_types_are_penalised_when_wrong_and_attempted(): void
    {
        $s = $this->scenario();
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $s['quiz']->id,
            'type' => 'short_answer',
            'points' => 2,
            'sort_order' => 1,
            'settings' => ['negative_marking' => 0.5],
        ]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true, 'option_text' => 'Paris']);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'London']]);

        $this->assertSame(-1.0, $this->earned($s, $question->id));
    }

    public function test_a_fill_in_blank_attempt_penalises_only_when_every_blank_is_wrong(): void
    {
        $s = $this->scenario();
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $s['quiz']->id,
            'type' => 'fill_in_blank',
            'points' => 2,
            'sort_order' => 1,
            'settings' => ['blanks' => [['Laravel'], ['Eloquent']], 'negative_marking' => 0.5],
        ]);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => ['zzz', 'zzz']]]);
        $this->assertSame(-1.0, $this->earned($s, $question->id));

        $this->start($s);
        $this->submit($s, []);
        $this->assertSame(0.0, $this->earned($s, $question->id));
    }

    public function test_partial_credit_above_zero_is_not_penalised(): void
    {
        $s = $this->scenario();
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $s['quiz']->id,
            'type' => 'multi_select',
            'points' => 2,
            'sort_order' => 1,
            'settings' => ['partial_credit' => true, 'negative_marking' => 0.5],
        ]);
        $half = QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true]);
        $wrong = QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => false]);
        $this->start($s);

        // One of the two correct options, and nothing wrong: proportional credit
        // (1 of 2 pts). Above zero, so no penalty.
        $this->submit($s, [['question_id' => $question->id, 'answer' => [$half->id]]]);
        $this->assertSame(1.0, $this->earned($s, $question->id));

        // All wrong earns nothing and draws the penalty instead.
        $this->start($s);
        $this->submit($s, [['question_id' => $question->id, 'answer' => [$wrong->id]]]);
        $this->assertSame(-1.0, $this->earned($s, $question->id));
    }

    public function test_the_attempt_total_is_floored_at_zero(): void
    {
        $s = $this->scenario();
        $penalised = $this->mc($s['quiz'], points: 1, negative: 1.0);
        $this->start($s);

        $this->submit($s, [['question_id' => $penalised->id, 'answer' => $this->wrongOption($penalised)->id]]);

        $this->assertSame(-1.0, $this->earned($s, $penalised->id));
        $this->assertSame(0.0, $this->score($s));
        $this->assertSame(0.0, $this->percentage($s));
        $this->assertFalse($this->passed($s));
    }

    // ----------------------------------------------------------- disclosure

    public function test_the_penalty_is_disclosed_to_the_student_on_start(): void
    {
        $s = $this->scenario();
        $penalised = $this->mc($s['quiz'], points: 1, negative: 0.25);
        $plain = $this->mc($s['quiz'], points: 1, negative: null);

        $response = $this->start($s);

        $byId = collect($response->json('questions'))->keyBy('id');
        $this->assertEquals(0.25, $byId[$penalised->id]['settings']['negative_marking']);
        $this->assertEquals(0.0, $byId[$plain->id]['settings']['negative_marking']);
    }

    public function test_the_penalty_is_disclosed_on_the_review(): void
    {
        $s = $this->scenario();
        $penalised = $this->mc($s['quiz'], points: 1, negative: 0.25);
        $this->start($s);
        $this->submit($s, [['question_id' => $penalised->id, 'answer' => $this->wrongOption($penalised)->id]]);

        $response = $this->actingAs($s['student'])
            ->getJson("/api/quiz-attempts/{$this->attemptId($s)}");

        $response->assertOk();
        $this->assertSame(-0.25, $response->json('questions.0.points_earned'));
        $this->assertEquals(0.25, $response->json('questions.0.settings.negative_marking'));
    }

    public function test_disclosing_the_penalty_never_discloses_the_answer_key(): void
    {
        $s = $this->scenario();
        $numeric = QuizQuestion::factory()->create([
            'quiz_id' => $s['quiz']->id,
            'type' => 'numeric',
            'points' => 1,
            'sort_order' => 1,
            'settings' => ['answer' => 42, 'negative_marking' => 0.5],
        ]);
        $blanks = QuizQuestion::factory()->create([
            'quiz_id' => $s['quiz']->id,
            'type' => 'fill_in_blank',
            'points' => 2,
            'sort_order' => 2,
            'settings' => ['blanks' => [['Laravel']], 'negative_marking' => 0.5],
        ]);

        $response = $this->start($s);

        $byId = collect($response->json('questions'))->keyBy('id');
        $this->assertArrayNotHasKey('answer', $byId[$numeric->id]['settings']);
        $this->assertArrayNotHasKey('blanks', $byId[$blanks->id]['settings']);
        $this->assertEquals(0.5, $byId[$numeric->id]['settings']['negative_marking']);
        $this->assertEquals(0.5, $byId[$blanks->id]['settings']['negative_marking']);
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

    private function mc(Quiz $quiz, float $points = 1, ?float $negative = null): QuizQuestion
    {
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'type' => 'multiple_choice',
            'points' => $points,
            'sort_order' => QuizQuestion::where('quiz_id', $quiz->id)->count() + 1,
            'settings' => $negative === null ? null : ['negative_marking' => $negative],
        ]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => false]);

        return $question;
    }

    private function correctOption(QuizQuestion $question): QuizOption
    {
        return $question->options()->where('is_correct', true)->firstOrFail();
    }

    private function wrongOption(QuizQuestion $question): QuizOption
    {
        return $question->options()->where('is_correct', false)->firstOrFail();
    }

    private function attemptId(array $s): int
    {
        return QuizAttempt::where('quiz_id', $s['quiz']->id)
            ->where('student_id', $s['student']->id)
            ->latest('id')
            ->value('id');
    }

    private function earned(array $s, int $questionId): float
    {
        return (float) QuizAttempt::findOrFail($this->attemptId($s))
            ->answers()
            ->where('quiz_question_id', $questionId)
            ->value('points_earned');
    }

    private function score(array $s): float
    {
        return (float) QuizAttempt::findOrFail($this->attemptId($s))->score;
    }

    private function percentage(array $s): float
    {
        return (float) QuizAttempt::findOrFail($this->attemptId($s))->score_percentage;
    }

    private function passed(array $s): bool
    {
        return (bool) QuizAttempt::findOrFail($this->attemptId($s))->passed;
    }

    private function start(array $s)
    {
        return $this->actingAs($s['student'])->postJson("/api/quizzes/{$s['quiz']->id}/start");
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
