<?php

namespace Tests\Feature;

use App\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeAdjustment;
use App\Models\GradebookCategory;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Instructor decisions about a grade: a manual score, a grade excluded from the
 * average, a note, and the trail those decisions leave behind.
 *
 * The recorded ledger row is deliberately never rewritten. A grade an instructor
 * changed is a grade whose report and its evidence disagree, and a student
 * contesting a mark needs the graded result to still be there. So the gradebook
 * reports the adjustment while `grades` keeps what the grader produced, and the
 * trail records who decided what.
 */
class InstructorGradebookAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- overrides

    public function test_a_manual_score_replaces_the_reported_grade_and_leaves_the_recorded_one_alone(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 8, 'note' => 'Regraded after the marking complaint.'])->assertOk();

        $cell = $this->cell($course, 'Alice', "quiz-{$quiz->id}");

        $this->assertSame(80.0, (float) $cell['percentage']);
        $this->assertSame(8.0, (float) $cell['score']);
        $this->assertTrue($cell['overridden']);

        // The evidence the decision was made against is still there.
        $this->assertSame(60.0, (float) $cell['recorded_percentage']);
        $this->assertSame(6.0, (float) $cell['recorded_score']);
        $this->assertDatabaseHas('grades', ['id' => $grade->id, 'score' => 6, 'percentage' => 60]);

        // And it is the reported number the course grade is built from.
        $this->assertSame(80.0, (float) $this->row($course, 'Alice')['course_percentage']);
    }

    public function test_an_override_percentage_is_derived_from_the_score_not_taken_from_it(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 8, 'max_score' => 20])->assertOk();

        $this->assertSame(40.0, (float) $this->cell($course, 'Alice', "quiz-{$quiz->id}")['percentage']);
    }

    public function test_a_first_override_keeps_the_graded_maximum(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        // No max submitted: the denominator stays the one the quiz was marked
        // out of, rather than becoming 1 and reporting 800%.
        $this->adjust($course, $grade, ['score' => 8])->assertOk();

        $cell = $this->cell($course, 'Alice', "quiz-{$quiz->id}");

        $this->assertSame(10.0, (float) $cell['max_score']);
        $this->assertSame(80.0, (float) $cell['percentage']);
    }

    public function test_a_score_higher_than_the_maximum_is_refused(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 12])
            ->assertStatus(422)
            ->assertJsonValidationErrors('score');

        $this->assertDatabaseCount('grade_adjustments', 0);
    }

    public function test_clearing_an_override_returns_the_cell_to_the_recorded_score(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 8])->assertOk();
        $this->adjust($course, $grade, ['score' => null, 'note' => null])->assertOk();

        $cell = $this->cell($course, 'Alice', "quiz-{$quiz->id}");

        $this->assertFalse($cell['overridden']);
        $this->assertSame(60.0, (float) $cell['percentage']);
        $this->assertSame(6.0, (float) $cell['score']);
        $this->assertDatabaseHas('grade_adjustments', ['grade_id' => $grade->id, 'action' => 'clear_override']);
    }

    public function test_an_override_outlives_a_regrade_of_the_underlying_attempt(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 8, 'note' => 'Instructor decision, do not revert.'])->assertOk();

        // A grader fix rewrites the ledger row. Silently discarding the
        // instructor's decision with it would undo an explicit human call, so the
        // adjustment stays and both numbers remain visible.
        $grade->update(['score' => 3, 'percentage' => 30]);
        $grade->source->update(['score' => 3, 'score_percentage' => 30]);

        $cell = $this->cell($course, 'Alice', "quiz-{$quiz->id}");

        $this->assertSame(80.0, (float) $cell['percentage']);
        $this->assertSame(30.0, (float) $cell['recorded_percentage']);
    }

    // ---------------------------------------------------------------- drops

    public function test_a_dropped_grade_counts_for_nothing_in_the_course_percentage(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $midterm = $this->quiz($course, 'Midterm');
        $essay = $this->quiz($course, 'Essay');
        $this->gradeAttempt($course, $midterm, $alice, 9, 10);
        $dropped = $this->gradeAttempt($course, $essay, $alice, 5, 10);

        $this->adjust($course, $dropped, ['dropped' => true, 'note' => 'Marked against the wrong rubric.'])->assertOk();

        $row = $this->row($course, 'Alice');

        // (90 + 50) / 2 would be 70; the dropped essay is out of it entirely.
        $this->assertSame(90.0, (float) $row['course_percentage']);
        $this->assertSame(1, $row['graded_count']);

        // The cell is still there: the instructor has to be able to see the grade
        // exists, and why it is not counting.
        $cell = $this->cell($course, 'Alice', "quiz-{$essay->id}");
        $this->assertTrue($cell['dropped']);
        $this->assertSame(50.0, (float) $cell['percentage']);
        $this->assertSame('Marked against the wrong rubric.', $cell['note']);
    }

    public function test_a_dropped_grade_is_left_out_of_the_class_average(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $bob = $this->enroll($course, 'Bob');
        $quiz = $this->quiz($course, 'Midterm');

        $this->gradeAttempt($course, $quiz, $alice, 9, 10);
        $dropped = $this->gradeAttempt($course, $quiz, $bob, 2, 10);

        $this->adjust($course, $dropped, ['dropped' => true])->assertOk();

        // Bob's 20% is real, it is just not evidence about this column.
        $this->assertSame(90.0, (float) $this->columnAverage($course, "quiz-{$quiz->id}"));
    }

    public function test_a_dropped_grade_is_left_out_of_its_category_subtotal(): void
    {
        $course = Course::factory()->create();
        $category = GradebookCategory::factory()->for($course)->create(['weight' => 100]);
        $alice = $this->enroll($course, 'Alice');

        $first = $this->quiz($course, 'Quiz one', $category);
        $second = $this->quiz($course, 'Quiz two', $category);
        $this->gradeAttempt($course, $first, $alice, 9, 10);
        $dropped = $this->gradeAttempt($course, $second, $alice, 5, 10);

        $this->adjust($course, $dropped, ['dropped' => true])->assertOk();

        $row = $this->row($course, 'Alice');

        $this->assertSame(90.0, (float) $row['category_percentages']["category-{$category->id}"]);
        $this->assertSame(90.0, (float) $row['course_percentage']);
    }

    public function test_a_student_whose_every_grade_is_dropped_has_no_course_grade(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 9, 10);

        $this->adjust($course, $grade, ['dropped' => true])->assertOk();

        $row = $this->row($course, 'Alice');

        $this->assertNull($row['course_percentage']);
        $this->assertSame(0, $row['graded_count']);
        $this->assertNull($this->columnAverage($course, "quiz-{$quiz->id}"));
    }

    public function test_restoring_a_dropped_grade_puts_it_back_in_every_average(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 5, 10);

        $this->adjust($course, $grade, ['dropped' => true])->assertOk();
        $this->adjust($course, $grade, ['dropped' => false])->assertOk();

        $this->assertFalse($this->cell($course, 'Alice', "quiz-{$quiz->id}")['dropped']);
        $this->assertSame(50.0, (float) $this->row($course, 'Alice')['course_percentage']);
        $this->assertSame(50.0, (float) $this->columnAverage($course, "quiz-{$quiz->id}"));
    }

    public function test_dropping_an_overridden_grade_keeps_the_override_underneath(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 2, 10);

        $this->adjust($course, $grade, ['score' => 9])->assertOk();
        $this->adjust($course, $grade, ['dropped' => true])->assertOk();

        $dropped = $this->cell($course, 'Alice', "quiz-{$quiz->id}");

        // Excluding a grade is a separate decision from changing it, so neither
        // undoes the other.
        $this->assertTrue($dropped['dropped']);
        $this->assertTrue($dropped['overridden']);
        $this->assertSame(90.0, (float) $dropped['percentage']);

        $this->adjust($course, $grade, ['dropped' => false])->assertOk();

        $restored = $this->cell($course, 'Alice', "quiz-{$quiz->id}");
        $this->assertFalse($restored['dropped']);
        $this->assertSame(90.0, (float) $restored['percentage']);
    }

    public function test_an_assignment_grade_can_be_adjusted_too(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $assignment = $this->assignment($course, 'Intro essay');
        $grade = $this->gradeAssignment($course, $assignment, $alice, 40, 100);

        $this->adjust($course, $grade, ['dropped' => true])->assertOk();

        $this->assertTrue($this->cell($course, 'Alice', "assignment-{$assignment->id}")['dropped']);
        $this->assertNull($this->row($course, 'Alice')['course_percentage']);
    }

    // ------------------------------------------------------- the no-op case

    public function test_saving_an_untouched_grade_records_nothing(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        // A dialog that submits what is already in force is not a decision, and a
        // trail padded with no-op rows would bury the ones that are.
        $this->adjust($course, $grade, ['dropped' => false])
            ->assertOk()
            ->assertJsonPath('changed', false);

        $this->assertDatabaseCount('grade_adjustments', 0);
    }

    public function test_resubmitting_the_same_adjustment_records_nothing_either(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 8])->assertOk();
        $this->adjust($course, $grade, ['score' => 8])->assertOk()->assertJsonPath('changed', false);

        $this->assertDatabaseCount('grade_adjustments', 1);
    }

    // ------------------------------------------------------------- the trail

    public function test_every_decision_is_recorded_with_who_made_it_and_when(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 8])->assertOk();
        $this->adjust($course, $grade, ['dropped' => true])->assertOk();
        $this->adjust($course, $grade, ['note' => 'Appeal upheld, reassessed manually.'])->assertOk();

        $actions = GradeAdjustment::where('grade_id', $grade->id)->orderBy('id')->pluck('action')->all();

        // The last one only changed the note, which is its own kind of decision
        // and would otherwise vanish from the record entirely.
        $this->assertSame(['override', 'drop', 'annotate'], array_map(fn ($action): string => $action->value, $actions));

        $this->assertDatabaseHas('grade_adjustments', [
            'grade_id' => $grade->id,
            'student_id' => $alice->id,
            'course_id' => $course->id,
            'action' => 'override',
            'score' => 8,
            'max_score' => 10,
            'percentage' => 80,
            'adjusted_by' => $course->instructor_id,
        ]);
    }

    public function test_the_trail_reads_as_a_course_wide_history(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $bob = $this->enroll($course, 'Bob');
        $midterm = $this->quiz($course, 'Midterm');
        $essay = $this->assignment($course, 'Intro essay');

        $this->adjust($course, $this->gradeAttempt($course, $midterm, $alice, 6, 10), ['score' => 8])->assertOk();
        $this->adjust($course, $this->gradeAssignment($course, $essay, $bob, 40, 100), ['dropped' => true])->assertOk();

        $entries = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook/adjustments")
            ->assertOk()
            ->assertJsonCount(2, 'adjustments')
            ->json('adjustments');

        $this->assertSame('drop', $entries[0]['action']);
        $this->assertSame('Intro essay', $entries[0]['assessment']['title']);
        $this->assertSame('assignment', $entries[0]['assessment']['type']);
        $this->assertSame($bob->name, $entries[0]['student']['name']);
        $this->assertSame($course->instructor->name, $entries[0]['adjuster']['name']);
        $this->assertNotNull($entries[0]['adjusted_at']);

        $this->assertSame('override', $entries[1]['action']);
        $this->assertSame(80.0, (float) $entries[1]['percentage']);
        $this->assertSame('Midterm', $entries[1]['assessment']['title']);
    }

    public function test_a_trail_entry_says_what_the_grade_was_before(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 8])->assertOk();
        $this->adjust($course, $grade, ['dropped' => true])->assertOk();

        $entries = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook/adjustments")
            ->assertOk()
            ->json('adjustments');

        // The contestable claim is what the grade was before, so the trail has to
        // carry it rather than only the number it became.
        $this->assertSame('Excluded from the course grade, keeping the adjusted 8 of 10 (80%)', $entries[0]['summary']);
        $this->assertSame(80.0, (float) $entries[0]['from']['percentage']);
        $this->assertTrue($entries[0]['from']['overridden']);
        $this->assertFalse($entries[0]['from']['dropped']);

        $this->assertSame('Score changed from 6 of 10 (60%) to 8 of 10 (80%)', $entries[1]['summary']);
        $this->assertSame(60.0, (float) $entries[1]['from']['percentage']);
        $this->assertSame(6.0, (float) $entries[1]['from']['score']);
        $this->assertFalse($entries[1]['from']['overridden']);
    }

    public function test_the_first_adjustment_on_a_grade_is_compared_with_the_recorded_one(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $grade = $this->gradeAttempt($course, $this->quiz($course, 'Midterm'), $alice, 6, 10);

        $this->adjust($course, $grade, ['dropped' => true])->assertOk();

        $entry = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook/adjustments")
            ->assertOk()
            ->json('adjustments.0');

        // Nothing was adjusted before this, so what it was before is the grade as
        // the grader recorded it -- not an absence.
        $this->assertSame('Excluded from the course grade', $entry['summary']);
        $this->assertFalse($entry['from']['dropped']);
        $this->assertSame(6.0, (float) $entry['from']['score']);
        $this->assertSame(60.0, (float) $entry['from']['percentage']);
    }

    public function test_the_trail_of_another_course_is_not_reachable(): void
    {
        $course = Course::factory()->create();
        $other = Course::factory()->create();
        $alice = $this->enroll($other, 'Alice');
        $grade = $this->gradeAttempt($other, $this->quiz($other, 'Other midterm'), $alice, 6, 10);

        $this->adjust($other, $grade, ['score' => 8])->assertOk();

        $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook/adjustments")
            ->assertOk()
            ->assertJsonCount(0, 'adjustments');
    }

    // ---------------------------------------------------------------- access

    public function test_only_the_course_owner_or_an_admin_may_adjust_a_grade(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $grade = $this->gradeAttempt($course, $this->quiz($course, 'Midterm'), $alice, 6, 10);
        $url = "/api/instructor/courses/{$course->slug}/gradebook/grades/{$grade->id}";

        $this->actingAs(User::factory()->instructor()->create())
            ->putJson($url, ['score' => 8])
            ->assertForbidden();

        $this->actingAs($alice)
            ->putJson($url, ['score' => 8])
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson($url, ['score' => 8])
            ->assertOk();
    }

    public function test_a_grade_from_another_course_is_not_found(): void
    {
        $course = Course::factory()->create();
        $other = Course::factory()->create();
        $alice = $this->enroll($other, 'Alice');
        $grade = $this->gradeAttempt($other, $this->quiz($other, 'Other midterm'), $alice, 6, 10);

        $this->actingAs($course->instructor)
            ->putJson("/api/instructor/courses/{$course->slug}/gradebook/grades/{$grade->id}", ['score' => 8])
            ->assertNotFound();

        $this->assertDatabaseCount('grade_adjustments', 0);
    }

    public function test_a_student_cannot_read_the_trail(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $this->actingAs($alice)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook/adjustments")
            ->assertForbidden();
    }

    // ------------------------------------------------------- the student view

    public function test_the_portfolio_reports_the_adjusted_score(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');
        $grade = $this->gradeAttempt($course, $quiz, $alice, 6, 10);

        $this->adjust($course, $grade, ['score' => 8])->assertOk();

        // The student's own view of a grade is the same report the gradebook is:
        // two numbers for one grade would be the bug, not the feature.
        $reported = $this->actingAs($alice)
            ->getJson('/api/portfolio')
            ->assertOk()
            ->json('data.recent_grades.0');

        $this->assertSame(80.0, (float) $reported['percentage']);
        $this->assertSame(8.0, (float) $reported['score']);
        $this->assertTrue($reported['overridden']);
        $this->assertFalse($reported['dropped']);
    }

    public function test_the_portfolio_marks_a_grade_that_no_longer_counts(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $grade = $this->gradeAttempt($course, $this->quiz($course, 'Midterm'), $alice, 5, 10);

        $this->adjust($course, $grade, ['dropped' => true])->assertOk();

        $reported = $this->actingAs($alice)
            ->getJson('/api/portfolio')
            ->assertOk()
            ->json('data.recent_grades.0');

        // The mark is still reported -- it was really awarded -- but flagged, so a
        // student can tell it is not part of their course grade.
        $this->assertTrue($reported['dropped']);
        $this->assertFalse($reported['overridden']);
        $this->assertSame(50.0, (float) $reported['percentage']);
    }

    // ---------------------------------------------------------------- helpers

    private function enroll(Course $course, string $name, string $status = EnrollmentStatus::Active->value): User
    {
        $student = User::factory()->student()->create(['name' => $name]);

        Enrollment::factory()->create([
            'course_id' => $course->id,
            'student_id' => $student->id,
            'status' => $status,
            'progress_percent' => $status === EnrollmentStatus::Completed->value ? 100 : 0,
        ]);

        return $student;
    }

    private function quiz(Course $course, string $title, ?GradebookCategory $category = null): Quiz
    {
        return Quiz::factory()->create([
            'course_id' => $course->id,
            'title' => $title,
            'category_id' => $category?->id,
        ]);
    }

    private function assignment(Course $course, string $title): Assignment
    {
        return Assignment::factory()->create(['course_id' => $course->id, 'title' => $title]);
    }

    private function gradeAttempt(Course $course, Quiz $quiz, User $student, float $score, float $maxScore): Grade
    {
        $percentage = round(($score / $maxScore) * 100, 2);

        $attempt = QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => $score,
            'score_percentage' => $percentage,
            'passed' => $percentage >= 70,
        ]);

        return Grade::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'source_type' => QuizAttempt::class,
            'source_id' => $attempt->id,
            'type' => 'quiz',
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'graded_at' => now(),
        ]);
    }

    private function gradeAssignment(Course $course, Assignment $assignment, User $student, float $score, float $maxScore): Grade
    {
        $submission = AssignmentSubmission::factory()->graded()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'grade' => $score,
        ]);

        return Grade::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'source_type' => AssignmentSubmission::class,
            'source_id' => $submission->id,
            'type' => 'assignment',
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2),
            'graded_at' => now(),
        ]);
    }

    private function adjust(Course $course, Grade $grade, array $payload): TestResponse
    {
        return $this->actingAs($course->instructor)
            ->putJson("/api/instructor/courses/{$course->slug}/gradebook/grades/{$grade->id}", $payload);
    }

    private function gradebook(Course $course, string $name): array
    {
        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        return collect($json['students'])->firstWhere('name', $name);
    }

    private function row(Course $course, string $name): array
    {
        return $this->gradebook($course, $name);
    }

    private function cell(Course $course, string $name, string $key): array
    {
        return $this->gradebook($course, $name)['cells'][$key];
    }

    private function columnAverage(Course $course, string $key): ?float
    {
        return collect($this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json('assessments'))
            ->firstWhere('key', $key)['average'];
    }
}
