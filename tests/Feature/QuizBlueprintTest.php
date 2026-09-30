<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizBlueprintRule;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\QuizService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quiz blueprints: the per-type quotas that shape a bank draw.
 *
 * A bank quiz otherwise samples the bank uniformly, so a 20-question paper can
 * come out as 20 multiple-choice items. A blueprint pins the shape while the
 * paper length stays `draw_size`.
 *
 * The quota is deliberately a floor and not a promise. A bank that runs short of
 * a type serves what it has rather than refusing to start, which is the same
 * rule the unconstrained draw already follows for `draw_size`. These tests pin
 * both halves of that: the shape is honoured when the bank can supply it, and a
 * shortfall degrades instead of failing.
 */
class QuizBlueprintTest extends TestCase
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

    /**
     * A bank with a known stock per question type, each question answerable so a
     * served paper can actually be submitted.
     *
     * @param  array<string, int>  $stockByType
     */
    private function makeBank(array $stockByType): QuestionBank
    {
        $bank = QuestionBank::factory()->create([
            'course_id' => $this->course->id,
            'created_by' => $this->instructor->id,
        ]);

        $order = 0;

        foreach ($stockByType as $type => $count) {
            foreach (range(1, $count) as $index) {
                $order++;

                $question = QuizQuestion::factory()->forBank($bank)->create([
                    'type' => $type,
                    'sort_order' => $order,
                    'question_text' => $type === 'fill_in_blank'
                        ? "Blank {$index} of {$type}? {{0}}"
                        : "Question {$index} of {$type}?",
                    'settings' => $this->settingsFor($type, $index),
                ]);

                if ($this->usesOptions($type)) {
                    QuizOption::factory()->correct()->create(['quiz_question_id' => $question->id, 'sort_order' => 1]);
                    QuizOption::factory()->create(['quiz_question_id' => $question->id, 'sort_order' => 2]);
                }
            }
        }

        return $bank->fresh();
    }

    private function usesOptions(string $type): bool
    {
        return in_array($type, ['multiple_choice', 'true_false', 'multi_select'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settingsFor(string $type, int $index): ?array
    {
        return match ($type) {
            'numeric' => ['accepted_value' => (string) $index, 'tolerance' => '0'],
            'short_answer' => ['accepted_answers' => ["answer {$index}"]],
            'fill_in_blank' => ['blanks' => [0 => ['answer '.$index]]],
            default => null,
        };
    }

    private function makeBankQuiz(QuestionBank $bank, int $drawSize): Quiz
    {
        return Quiz::factory()->for($this->course)->create([
            'question_bank_id' => $bank->id,
            'draw_size' => $drawSize,
        ]);
    }

    /**
     * @param  array<string, int>  $quotas
     */
    private function applyBlueprint(Quiz $quiz, array $quotas): Quiz
    {
        foreach ($quotas as $type => $count) {
            QuizBlueprintRule::create([
                'quiz_id' => $quiz->id,
                'question_type' => $type,
                'question_count' => $count,
            ]);
        }

        return $quiz->fresh();
    }

    private function makeLesson(): Lesson
    {
        $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

        return Lesson::factory()->create([
            'course_id' => $this->course->id,
            'section_id' => $section->id,
            'type' => 'text',
        ]);
    }

    private function enrollStudent(): void
    {
        (new EnrollmentService)->enroll($this->student, $this->course);
    }

    /**
     * Serve a paper and return the question types actually served, keyed by type.
     *
     * @return array<string, int>
     */
    private function serveTypeCounts(Quiz $quiz, ?User $student = null): array
    {
        $response = $this->actingAs($student ?? $this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated();

        $counts = [];

        foreach ($response->json('questions') as $question) {
            $counts[$question['type']] = ($counts[$question['type']] ?? 0) + 1;
        }

        return $counts;
    }

    // ------------------------------------------------------------ honouring it

    public function test_a_blueprint_pins_the_mix_of_the_paper(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank([
            'multiple_choice' => 10,
            'numeric' => 4,
            'short_answer' => 4,
        ]);
        $quiz = $this->makeBankQuiz($bank, 8);
        $this->applyBlueprint($quiz, [
            'multiple_choice' => 2,
            'numeric' => 2,
        ]);

        $counts = $this->serveTypeCounts($quiz);

        $this->assertSame(2, $counts['multiple_choice'] ?? 0, 'The multiple-choice quota was not honoured.');
        $this->assertSame(2, $counts['numeric'] ?? 0, 'The numeric quota was not honoured.');
    }

    public function test_a_pinned_quota_is_not_inflated_by_the_remaining_slots(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(['multiple_choice' => 10, 'numeric' => 10]);
        $quiz = $this->makeBankQuiz($bank, 8);
        $this->applyBlueprint($quiz, ['numeric' => 2]);

        $counts = $this->serveTypeCounts($quiz);

        // The four leftover slots are filled from the types the blueprint did not
        // name. Filling them from the whole bank would serve more numeric than
        // the author asked for, which makes the blueprint describe nothing.
        $this->assertSame(2, $counts['numeric'] ?? 0);
        $this->assertSame(6, $counts['multiple_choice'] ?? 0);
        $this->assertSame(8, array_sum($counts));
    }

    public function test_the_leftover_paper_is_filled_from_the_rest_of_the_bank(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(['multiple_choice' => 10, 'numeric' => 4]);
        $quiz = $this->makeBankQuiz($bank, 8);
        $this->applyBlueprint($quiz, ['numeric' => 2]);

        $counts = $this->serveTypeCounts($quiz);

        // 8 questions drawn, 2 pinned as numeric, so 6 filled from whatever was
        // left. A blueprint that only pinned questions and served 2 would be
        // ignoring the paper length the instructor set.
        $this->assertSame(8, array_sum($counts));
        $this->assertSame(2, $counts['numeric'] ?? 0);
    }

    public function test_the_paper_is_still_the_draw_size_when_the_quotas_fill_it(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(['multiple_choice' => 10, 'numeric' => 10]);
        $quiz = $this->makeBankQuiz($bank, 6);
        $this->applyBlueprint($quiz, ['multiple_choice' => 2, 'numeric' => 4]);

        $this->assertSame(6, array_sum($this->serveTypeCounts($quiz)));
    }

    public function test_two_students_get_different_questions_within_the_same_blueprint(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(['multiple_choice' => 10, 'numeric' => 10]);
        $quiz = $this->makeBankQuiz($bank, 4);
        $this->applyBlueprint($quiz, ['numeric' => 2]);

        $first = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated()
            ->json('questions.*.id');

        $other = User::factory()->student()->create();
        (new EnrollmentService)->enroll($other, $this->course);

        $second = $this->actingAs($other)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated()
            ->json('questions.*.id');

        $this->assertNotSame($first, $second, 'A blueprint draw served two students the same paper.');
    }

    public function test_a_quota_larger_than_the_stock_serves_what_exists(): void
    {
        $this->enrollStudent();
        // Only two numeric exist, so a quota of five cannot be met. The attempt
        // must still start, and the paper must still be the requested length.
        $bank = $this->makeBank(['multiple_choice' => 6, 'numeric' => 2]);
        $quiz = $this->makeBankQuiz($bank, 6);
        $this->applyBlueprint($quiz, ['multiple_choice' => 1, 'numeric' => 5]);

        $counts = $this->serveTypeCounts($quiz);

        $this->assertSame(2, $counts['numeric'] ?? 0, 'It should serve the two numeric questions the bank has.');

        // Both types are named by the blueprint and numeric came up three short,
        // so the top-up draws from whatever is left rather than leaving the
        // student a three-question paper.
        $this->assertSame(6, array_sum($counts));
        $this->assertSame(4, $counts['multiple_choice'] ?? 0);
    }

    public function test_a_draw_never_repeats_a_question_across_overlapping_quotas(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(['multiple_choice' => 8]);
        $quiz = $this->makeBankQuiz($bank, 4);
        $this->applyBlueprint($quiz, ['multiple_choice' => 2]);

        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated();

        $served = $response->json('questions.*.id');

        $this->assertCount(4, array_unique($served), 'The draw repeated a question.');
    }

    public function test_a_paper_is_not_served_grouped_by_type(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);
        $this->applyBlueprint($quiz, ['multiple_choice' => 5, 'numeric' => 5]);

        $order = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated()
            ->json('questions.*.type');

        $runs = 1;

        for ($i = 1, $total = count($order); $i < $total; $i++) {
            if ($order[$i] !== $order[$i - 1]) {
                $runs++;
            }
        }

        // Two types cannot produce fewer than two runs. A blueprint applied in
        // rule order would always give exactly two, which tells a student the
        // paper is sorted by type.
        $this->assertGreaterThan(2, $runs, 'The served paper was grouped by question type.');
    }

    public function test_a_quiz_without_a_blueprint_still_draws_a_uniform_sample(): void
    {
        // Seeded, because the property being asserted is a property of a *random*
        // draw. Drawing 6 from a 10/10 bank lands on a single type about 1.1% of
        // the time, and the test checks two draws, so it failed roughly one run in
        // 46 for reasons that had nothing to do with the code -- which is how a
        // suite learns to be re-run until green. `loadPackedQuestions()` shuffles
        // with Mt19937, so seeding makes the sample reproducible and turns the
        // assertion back into a real check on the sampler.
        mt_srand(20260930);

        $this->enrollStudent();
        $bank = $this->makeBank(['multiple_choice' => 10, 'numeric' => 10]);

        $quiz = $this->makeBankQuiz($bank, 6);

        // A second student rather than a second Start. An attempt already in
        // progress is resumed instead of replaced, so one student is only ever
        // served one paper at a time -- and independence between draws is a claim
        // about students, not about clicking twice.
        $second = User::factory()->student()->create();
        (new EnrollmentService)->enroll($second, $this->course);

        $first = $this->serveTypeCounts($quiz);
        $other = $this->serveTypeCounts($quiz, $second);

        $this->assertSame(6, array_sum($first));
        $this->assertSame(6, array_sum($other));

        // With no blueprint, a large draw from a balanced bank should not come
        // out as a single type every time.
        $this->assertGreaterThan(1, count($first), 'An unconstrained draw served a single question type.');
        $this->assertGreaterThan(1, count($other), 'An unconstrained draw served a single question type.');
    }

    // ------------------------------------------------------------- validation

    public function test_a_blueprint_cannot_be_set_without_a_bank(): void
    {
        $lesson = $this->makeLesson();

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/lessons/{$lesson->id}/quiz"), [
                'title' => 'No bank',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'blueprint' => [['type' => 'numeric', 'count' => 3]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('blueprint');
    }

    public function test_quotas_cannot_exceed_the_paper_length(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 4);
        $lesson = $this->makeLesson();
        $quiz->lesson()->create([
            'course_id' => $this->course->id,
            'section_id' => $lesson->section_id,
            'title' => 'Quiz',
            'type' => 'quiz',
            'quiz_id' => $quiz->id,
        ]);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 4,
                'blueprint' => [
                    ['type' => 'multiple_choice', 'count' => 3],
                    ['type' => 'numeric', 'count' => 3],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('blueprint');
    }

    public function test_a_quota_cannot_exceed_the_banks_stock_of_that_type(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 2]);
        $quiz = $this->makeBankQuiz($bank, 10);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 10,
                'blueprint' => [['type' => 'numeric', 'count' => 5]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('blueprint.0.count');
    }

    public function test_a_quota_for_a_type_the_bank_does_not_hold_is_refused(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 10,
                'blueprint' => [['type' => 'numeric', 'count' => 2]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('blueprint.0.count');
    }

    public function test_the_same_type_cannot_be_listed_twice(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 10,
                'blueprint' => [
                    ['type' => 'numeric', 'count' => 2],
                    ['type' => 'numeric', 'count' => 3],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('blueprint');
    }

    public function test_an_unknown_question_type_is_refused(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 10,
                'blueprint' => [['type' => 'essay', 'count' => 2]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('blueprint.0.type');
    }

    public function test_a_quota_of_zero_is_treated_as_no_quota(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 6);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 6,
                'blueprint' => [
                    ['type' => 'multiple_choice', 'count' => 0],
                    ['type' => 'numeric', 'count' => 0],
                ],
            ])
            ->assertOk();

        // A blank field in the form is the natural way to clear a quota, and it
        // must not be stored as a rule demanding zero questions.
        $this->assertSame(0, $quiz->fresh()->blueprintRules()->count());
    }

    // ------------------------------------------------------------ persistence

    public function test_saving_a_blueprint_replaces_the_previous_one(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20, 'short_answer' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 10,
                'blueprint' => [
                    ['type' => 'numeric', 'count' => 2],
                    ['type' => 'short_answer', 'count' => 3],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('quiz.blueprint.0.type', 'numeric')
            ->assertJsonPath('quiz.blueprint.0.count', 2)
            ->assertJsonCount(2, 'quiz.blueprint');

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 10,
                'blueprint' => [['type' => 'numeric', 'count' => 4]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'quiz.blueprint');

        // The dropped types must actually be gone. Leaving them would keep
        // drawing a paper the author believes they replaced.
        $this->assertSame(
            ['numeric' => 4],
            $quiz->fresh()->blueprintRules()->get()->mapWithKeys(
                fn (QuizBlueprintRule $rule): array => [$rule->question_type->value => $rule->question_count]
            )->all(),
        );
    }

    public function test_detaching_a_bank_drops_the_blueprint(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);
        $this->applyBlueprint($quiz, ['numeric' => 3]);

        $this->actingAs($this->instructor)
            ->postJson($this->baseUrl("/quizzes/{$quiz->id}?_method=PUT"), [
                'title' => 'Quiz',
                'passing_score' => 70,
                'attempts_allowed' => 1,
            ])
            ->assertOk();

        $this->assertNull($quiz->fresh()->question_bank_id);
        $this->assertSame(0, $quiz->blueprintRules()->count());
    }

    public function test_a_blueprint_is_returned_for_the_builder_to_load(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);
        $this->applyBlueprint($quiz, ['numeric' => 3, 'multiple_choice' => 2]);

        $this->actingAs($this->instructor)
            ->getJson($this->baseUrl("/quizzes/{$quiz->id}"))
            ->assertOk()
            // Ordered by type so the builder's inputs come back in a stable order
            // rather than in whatever order the rows were inserted.
            ->assertJsonPath('quiz.blueprint.0.type', 'multiple_choice')
            ->assertJsonPath('quiz.blueprint.0.count', 2)
            ->assertJsonPath('quiz.blueprint.1.type', 'numeric')
            ->assertJsonPath('quiz.blueprint.1.count', 3);
    }

    public function test_a_quiz_without_a_blueprint_reports_an_empty_one(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 5]);
        $quiz = $this->makeBankQuiz($bank, 3);

        $this->actingAs($this->instructor)
            ->getJson($this->baseUrl("/quizzes/{$quiz->id}"))
            ->assertOk()
            ->assertJsonPath('quiz.blueprint', []);
    }

    // --------------------------------------------------------------- snapshots

    public function test_a_served_paper_keeps_its_shape_after_the_blueprint_changes(): void
    {
        $this->enrollStudent();
        $bank = $this->makeBank(['multiple_choice' => 10, 'short_answer' => 10, 'numeric' => 10]);
        $quiz = $this->makeBankQuiz($bank, 6);
        $this->applyBlueprint($quiz, ['numeric' => 2]);

        $response = $this->actingAs($this->student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated();

        $servedTypes = array_column($response->json('questions'), 'type');
        $this->assertSame(2, count(array_filter($servedTypes, fn (string $t): bool => $t === 'numeric')));

        // Rewriting the blueprint must not reach back into a served attempt.
        $quiz->blueprintRules()->delete();
        $this->applyBlueprint($quiz, ['multiple_choice' => 6]);

        $attempt = QuizAttempt::query()->latest('id')->firstOrFail();

        $this->assertSame(
            $servedTypes,
            $attempt->questions()->get()->map(fn (QuizQuestion $question): string => $question->type->value)->all(),
            'The snapshot changed when the blueprint was edited.'
        );
    }

    public function test_quotas_are_rejected_when_they_cannot_be_persisted(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20]);
        $quiz = $this->makeBankQuiz($bank, 4);

        // The unique index on (quiz_id, question_type) is the backstop for a
        // duplicate that somehow got past validation, and it is a real
        // constraint rather than application-level tidiness.
        $this->expectException(QueryException::class);

        QuizBlueprintRule::create([
            'quiz_id' => $quiz->id,
            'question_type' => 'multiple_choice',
            'question_count' => 2,
        ]);

        QuizBlueprintRule::create([
            'quiz_id' => $quiz->id,
            'question_type' => 'multiple_choice',
            'question_count' => 2,
        ]);
    }

    public function test_deleting_a_quiz_deletes_its_blueprint(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);
        $this->applyBlueprint($quiz, ['numeric' => 3]);

        $quiz->delete();

        $this->assertSame(0, QuizBlueprintRule::query()->count());
    }

    public function test_two_quizzes_can_sit_the_same_bank_differently(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);

        $numericHeavy = $this->makeBankQuiz($bank, 6);
        $this->applyBlueprint($numericHeavy, ['numeric' => 5]);

        $mcHeavy = $this->makeBankQuiz($bank, 6);
        $this->applyBlueprint($mcHeavy, ['multiple_choice' => 5]);

        $this->enrollStudent();

        $first = array_column(
            $this->actingAs($this->student)->postJson("/api/quizzes/{$numericHeavy->id}/start")->json('questions'),
            'type',
        );
        $second = array_column(
            $this->actingAs($this->student)->postJson("/api/quizzes/{$mcHeavy->id}/start")->json('questions'),
            'type',
        );

        $this->assertSame(5, count(array_filter($first, fn (string $t): bool => $t === 'numeric')));
        $this->assertSame(5, count(array_filter($second, fn (string $t): bool => $t === 'multiple_choice')));
    }

    public function test_a_blueprint_is_scoped_to_its_own_quiz(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $first = $this->makeBankQuiz($bank, 6);
        $second = $this->makeBankQuiz($bank, 6);

        $this->applyBlueprint($first, ['numeric' => 3]);

        $this->assertSame(3, $first->fresh()->blueprintQuestionCount());
        $this->assertSame(0, $second->fresh()->blueprintQuestionCount());
    }

    public function test_a_quota_is_only_editable_through_the_course_that_owns_the_quiz(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 6);

        $intruder = User::factory()->instructor()->create();

        $this->actingAs($intruder)
            ->postJson("/api/instructor/courses/{$this->course->slug}/quizzes/{$quiz->id}?_method=PUT", [
                'title' => 'Hijacked',
                'passing_score' => 70,
                'attempts_allowed' => 1,
                'question_bank_id' => $bank->id,
                'draw_size' => 6,
                'blueprint' => [['type' => 'numeric', 'count' => 2]],
            ])
            ->assertForbidden();

        $this->assertSame(0, $quiz->fresh()->blueprintRules()->count());
    }

    public function test_the_paper_length_ignores_quotas_when_a_quiz_owns_its_questions(): void
    {
        $quiz = Quiz::factory()->for($this->course)->create();
        $this->applyBlueprint($quiz, ['numeric' => 3]);

        // A quiz that owns its questions is never drawn from, so a stray
        // blueprint must not change what it serves.
        $this->assertSame(
            $quiz->questions()->count(),
            app(QuizService::class)->resolveQuestions($quiz->fresh())->count(),
        );
    }

    public function test_the_blueprint_total_is_reported_without_an_extra_query_when_loaded(): void
    {
        $bank = $this->makeBank(['multiple_choice' => 20, 'numeric' => 20]);
        $quiz = $this->makeBankQuiz($bank, 10);
        $this->applyBlueprint($quiz, ['numeric' => 2, 'short_answer' => 3]);

        $loaded = $quiz->fresh()->load('blueprintRules');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame(5, $loaded->blueprintQuestionCount());
        $this->assertSame(0, $queries, 'A loaded blueprint still hit the database.');
    }
}
