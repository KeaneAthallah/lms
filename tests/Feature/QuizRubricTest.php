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
 * Rubrics for short-answer questions: an ordered list of criteria, each with
 * the whole terms that signal it and how many points it is worth.
 *
 * A rubric swaps the exact-match scoring rule for criteria scoring: an answer
 * that is wrong as a whole but contains the right ideas earns credit for the
 * criteria it names. The terms are the scoring rule, so they are hidden from
 * students the same way an exact answer is, while the criteria labels and
 * points are disclosed so a student knows what is being assessed.
 */
class QuizRubricTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ authoring

    public function test_an_instructor_can_save_a_rubric_on_a_short_answer_question(): void
    {
        $s = $this->scenario();
        $owner = $this->courseOwner($s);

        $response = $this->actingAs($owner)->postJson($this->authoringUrl($s), [
            'type' => 'short_answer',
            'question_text' => 'State Newton second law of motion.',
            'points' => 2,
            'settings' => [
                'negative_marking' => 0,
                'rubric' => [
                    ['label' => 'States the law', 'keywords' => ['newton'], 'points' => 1],
                    ['label' => 'Names acceleration', 'keywords' => ['acceleration', 'f=ma'], 'points' => 1],
                ],
            ],
            'options' => [['option_text' => 'F=ma', 'is_correct' => true]],
        ]);

        $response->assertCreated();
        $question = QuizQuestion::findOrFail($response->json('question.id'));
        $this->assertSame('newton', $question->settings['rubric'][0]['keywords'][0]);
        $this->assertSame(1.0, (float) $question->settings['rubric'][0]['points']);
    }

    public function test_a_rubric_only_applies_to_short_answer_questions(): void
    {
        $s = $this->scenario();
        $owner = $this->courseOwner($s);

        $this->actingAs($owner)->postJson($this->authoringUrl($s), [
            'type' => 'multiple_choice',
            'question_text' => 'Pick one.',
            'points' => 1,
            'settings' => [
                'rubric' => [['label' => 'A', 'keywords' => ['a'], 'points' => 1]],
            ],
            'options' => [
                ['option_text' => 'a', 'is_correct' => true],
                ['option_text' => 'b', 'is_correct' => false],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.rubric');
    }

    public function test_a_rubric_needs_at_least_one_criterion(): void
    {
        $s = $this->scenario();
        $owner = $this->courseOwner($s);

        $this->actingAs($owner)->postJson($this->authoringUrl($s), [
            'type' => 'short_answer',
            'question_text' => 'Explain.',
            'points' => 1,
            'settings' => ['rubric' => []],
            'options' => [['option_text' => 'x', 'is_correct' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.rubric');
    }

    public function test_a_criterion_needs_a_label_points_and_a_keyword(): void
    {
        $s = $this->scenario();
        $owner = $this->courseOwner($s);

        $response = $this->actingAs($owner)->postJson($this->authoringUrl($s), [
            'type' => 'short_answer',
            'question_text' => 'Explain.',
            'points' => 1,
            'settings' => [
                'rubric' => [
                    ['label' => '', 'keywords' => ['a'], 'points' => 1],
                    ['label' => 'B', 'keywords' => [], 'points' => 1],
                    ['label' => 'C', 'keywords' => ['c'], 'points' => 0],
                    ['label' => 'D', 'keywords' => ['d'], 'points' => 'heavy'],
                ],
            ],
            'options' => [['option_text' => 'x', 'is_correct' => true]],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('settings.rubric.0.label');
        $response->assertJsonValidationErrors('settings.rubric.1.keywords');
        $response->assertJsonValidationErrors('settings.rubric.2.points');
        $response->assertJsonValidationErrors('settings.rubric.3.points');
    }

    public function test_a_rubric_is_capped_at_ten_criteria(): void
    {
        $s = $this->scenario();
        $owner = $this->courseOwner($s);

        $criteria = collect(range(1, 11))->map(
            fn (int $i): array => ['label' => "C{$i}", 'keywords' => ["k{$i}"], 'points' => 1],
        )->all();

        $this->actingAs($owner)->postJson($this->authoringUrl($s), [
            'type' => 'short_answer',
            'question_text' => 'Explain.',
            'points' => 1,
            'settings' => ['rubric' => $criteria],
            'options' => [['option_text' => 'x', 'is_correct' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.rubric');
    }

    // -------------------------------------------------------------- grading

    public function test_a_rubric_awards_credit_per_matched_criterion(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 2, criteria: [
            ['label' => 'States the law', 'keywords' => ['newton'], 'points' => 1],
            ['label' => 'Names acceleration', 'keywords' => ['acceleration'], 'points' => 1],
        ]);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'Newton second law.']]);
        $this->assertSame(1.0, $this->earned($s, $question->id));

        $this->start($s);
        $this->submit($s, [['question_id' => $question->id, 'answer' => 'Newton law involves acceleration.']]);
        $this->assertSame(2.0, $this->earned($s, $question->id));

        $this->start($s);
        $this->submit($s, [['question_id' => $question->id, 'answer' => 'Completely unrelated.']]);
        $this->assertSame(0.0, $this->earned($s, $question->id));
    }

    public function test_any_one_keyword_marks_a_criterion_as_matched(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 1, criteria: [
            ['label' => 'Formula', 'keywords' => ['f=ma', 'acceleration'], 'points' => 1],
        ]);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'acceleration is the key']]);

        $this->assertSame(1.0, $this->earned($s, $question->id));
    }

    public function test_keyword_matching_is_case_insensitive_and_needs_a_whole_word(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 1, criteria: [
            ['label' => 'Mentions rate', 'keywords' => ['rate'], 'points' => 1],
        ]);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'The RATE of reaction']]);
        $this->assertSame(1.0, $this->earned($s, $question->id));

        $this->start($s);
        $this->submit($s, [['question_id' => $question->id, 'answer' => 'separate the variables']]);
        $this->assertSame(0.0, $this->earned($s, $question->id));
    }

    public function test_a_rubric_cannot_pay_out_more_than_the_question_is_worth(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 1, criteria: [
            ['label' => 'First idea', 'keywords' => ['first'], 'points' => 1],
            ['label' => 'Second idea', 'keywords' => ['second'], 'points' => 1],
        ]);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'first thing second thing']]);

        $this->assertSame(1.0, $this->earned($s, $question->id));
    }

    public function test_a_blank_answer_scores_zero_with_a_rubric(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 1, criteria: [
            ['label' => 'Idea', 'keywords' => ['idea'], 'points' => 1],
        ]);
        $this->start($s);

        $this->submit($s, []);

        $this->assertSame(0.0, $this->earned($s, $question->id));
    }

    public function test_without_a_rubric_short_answer_stays_an_exact_match(): void
    {
        $s = $this->scenario();
        $question = $this->shortAnswer($s['quiz'], points: 1, answer: 'Paris');
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'paris ']]);
        $this->assertSame(1.0, $this->earned($s, $question->id));
    }

    public function test_a_rubric_feeds_the_attempt_score_and_gradebook(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 2, criteria: [
            ['label' => 'States the law', 'keywords' => ['newton'], 'points' => 1],
            ['label' => 'Names acceleration', 'keywords' => ['acceleration'], 'points' => 1],
        ]);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'Newton law.']]);

        $attempt = QuizAttempt::findOrFail($this->attemptId($s));
        $this->assertSame(1.0, (float) $attempt->score);
        $this->assertSame(50.0, (float) $attempt->score_percentage);
        $this->assertDatabaseHas('grades', ['source_id' => $attempt->id, 'score' => 1.0]);
    }

    // ------------------------------------------- negative-marking interplay

    public function test_a_partially_correct_rubric_answer_is_not_penalised(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 2, negative: 0.5, criteria: [
            ['label' => 'States the law', 'keywords' => ['newton'], 'points' => 1],
            ['label' => 'Names acceleration', 'keywords' => ['acceleration'], 'points' => 1],
        ]);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'Newton law.']]);

        $this->assertSame(1.0, $this->earned($s, $question->id));
    }

    public function test_a_fully_wrong_rubric_answer_is_penalised_but_a_blank_is_not(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 2, negative: 0.5, criteria: [
            ['label' => 'States the law', 'keywords' => ['newton'], 'points' => 1],
            ['label' => 'Names acceleration', 'keywords' => ['acceleration'], 'points' => 1],
        ]);
        $this->start($s);

        $this->submit($s, [['question_id' => $question->id, 'answer' => 'Completely unrelated.']]);
        $this->assertSame(-1.0, $this->earned($s, $question->id));

        $this->start($s);
        $this->submit($s, []);
        $this->assertSame(0.0, $this->earned($s, $question->id));
    }

    // ----------------------------------------------------------- disclosure

    public function test_a_student_sees_rubric_labels_and_points_but_never_keywords(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 2, criteria: [
            ['label' => 'States the law', 'keywords' => ['newton'], 'points' => 1],
        ]);

        $response = $this->start($s);

        $settings = collect($response->json('questions'))->keyBy('id')[$question->id]['settings'];
        $this->assertSame('States the law', $settings['rubric'][0]['label']);
        $this->assertEquals(1.0, $settings['rubric'][0]['points']);
        $this->assertArrayNotHasKey('keywords', $settings['rubric'][0]);
        $this->assertSame('newton', $s['quiz']->questions()->findOrFail($question->id)->settings['rubric'][0]['keywords'][0]);
    }

    public function test_the_review_discloses_the_same_rubric_without_keywords(): void
    {
        $s = $this->scenario();
        $question = $this->rubric($s['quiz'], points: 2, criteria: [
            ['label' => 'States the law', 'keywords' => ['newton'], 'points' => 1],
        ]);
        $this->start($s);
        $this->submit($s, [['question_id' => $question->id, 'answer' => 'Newton law.']]);

        $response = $this->actingAs($s['student'])
            ->getJson("/api/quiz-attempts/{$this->attemptId($s)}");

        $response->assertOk();
        $rubric = collect($response->json('questions'))->keyBy('id')[$question->id]['settings']['rubric'];
        $this->assertSame('States the law', $rubric[0]['label']);
        $this->assertArrayNotHasKey('keywords', $rubric[0]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<int, array{label: string, keywords: string[], points: int|float}>  $criteria
     */
    private function rubric(Quiz $quiz, float $points = 1, array $criteria = [], ?float $negative = null): QuizQuestion
    {
        $settings = ['rubric' => $criteria];

        if ($negative !== null) {
            $settings['negative_marking'] = $negative;
        }

        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'type' => 'short_answer',
            'points' => $points,
            'sort_order' => 1,
            'settings' => $settings,
        ]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true, 'option_text' => 'x']);

        return $question;
    }

    private function shortAnswer(Quiz $quiz, float $points = 1, string $answer = ''): QuizQuestion
    {
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'type' => 'short_answer',
            'points' => $points,
            'sort_order' => 1,
        ]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true, 'option_text' => $answer]);

        return $question;
    }

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

    private function courseOwner(array $s): User
    {
        $owner = User::factory()->instructor()->create();
        $s['course']->update(['instructor_id' => $owner->id]);

        return $owner;
    }

    private function authoringUrl(array $s): string
    {
        return "/api/instructor/courses/{$s['course']->slug}/quizzes/{$s['quiz']->id}/questions";
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
