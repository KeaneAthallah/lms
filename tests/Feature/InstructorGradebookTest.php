<?php

namespace Tests\Feature;

use App\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The instructor gradebook: one column per course assessment (quiz or
 * assignment), one row per enrolled student, a class average under each column
 * and a course percentage on the right.
 *
 * The slice deliberately does not introduce categories, weights or dropped
 * grades yet; the course percentage is the simple mean of every graded
 * assessment. A quiz that allows retakes grades one ledger row per attempt, so
 * the cell is the student's best attempt; an assessment they never sat is a
 * dash, and a dash counts for nothing rather than a zero.
 */
class InstructorGradebookTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- access

    public function test_only_the_course_owner_or_an_admin_may_load_the_gradebook(): void
    {
        $course = Course::factory()->create();
        $owner = $course->instructor;
        $otherInstructor = User::factory()->instructor()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($otherInstructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk();

        $this->actingAs($owner)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk();
    }

    public function test_a_student_cannot_load_the_gradebook(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertForbidden();
    }

    // ------------------------------------------------------------- the grid

    public function test_the_grid_lists_students_and_assessments_in_order(): void
    {
        $course = Course::factory()->create();
        $this->enroll($course, 'Alice');
        $this->enroll($course, 'Bob');
        $this->enroll($course, 'Carol', EnrollmentStatus::Cancelled->value);

        $quiz = $this->quiz($course, 'Midterm');
        $assignment = $this->assignment($course, 'Intro essay');

        $response = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk();

        // Cancelled enrollments are not a row.
        $this->assertSame(['Alice', 'Bob'], collect($response->json('students'))->pluck('name')->all());

        // Columns run in title order across both assessment kinds.
        $this->assertSame(['assignment', 'quiz'], collect($response->json('assessments'))->pluck('type')->all());
        $this->assertSame(['Intro essay', 'Midterm'], collect($response->json('assessments'))->pluck('title')->all());
        $this->assertSame("quiz-{$quiz->id}", $response->json('assessments.1.key'));
        $this->assertSame($quiz->id, $response->json('assessments.1.id'));
        $this->assertSame("assignment-{$assignment->id}", $response->json('assessments.0.key'));

        // Nothing graded: every cell is a dash and there is no course grade yet.
        foreach ($response->json('students') as $student) {
            $this->assertNull($student['course_percentage']);
            $this->assertSame(0, $student['graded_count']);
            $this->assertNull($student['cells']["quiz-{$quiz->id}"]);
            $this->assertNull($student['cells']["assignment-{$assignment->id}"]);
        }

        $this->assertNull($response->json('assessments.0.average'));
        $this->assertNull($response->json('assessments.1.average'));
    }

    // ------------------------------------------------------------ quiz cells

    public function test_a_student_cell_is_their_best_quiz_attempt(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');

        $this->gradeAttempt($course, $quiz, $alice, 6, 10, 60);
        $this->gradeAttempt($course, $quiz, $alice, 9, 10, 90);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        $cell = $json['students'][0]['cells']["quiz-{$quiz->id}"];
        $this->assertSame(90.0, (float) $cell['percentage']);
        $this->assertSame(9.0, (float) $cell['score']);
        $this->assertSame(90.0, (float) $json['students'][0]['course_percentage']);
        $this->assertSame(90.0, (float) $json['assessments'][0]['average']);
    }

    public function test_a_zero_score_attempt_is_a_real_grade_not_a_dash(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $quiz = $this->quiz($course, 'Midterm');

        $this->gradeAttempt($course, $quiz, $alice, 0, 100, 0);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        $this->assertSame(0.0, (float) $json['students'][0]['cells']["quiz-{$quiz->id}"]['percentage']);
        $this->assertSame(0.0, (float) $json['students'][0]['course_percentage']);
        $this->assertSame(0.0, (float) $json['assessments'][0]['average']);
    }

    // ------------------------------------------------------- assignment cells

    public function test_assignment_grades_feed_the_course_grade(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $quiz = $this->quiz($course, 'Midterm');
        $assignment = $this->assignment($course, 'Intro essay');

        $this->gradeAttempt($course, $quiz, $alice, 9, 10, 90);
        $this->gradeAssignment($course, $assignment, $alice, 80, 100);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        $this->assertSame(80.0, (float) $json['students'][0]['cells']["assignment-{$assignment->id}"]['percentage']);
        // (90 + 80) / 2.
        $this->assertSame(85.0, (float) $json['students'][0]['course_percentage']);
        $this->assertSame(2, $json['students'][0]['graded_count']);
    }

    public function test_an_unassessed_assessment_counts_for_nothing(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $quiz = $this->quiz($course, 'Midterm');
        $assignment = $this->assignment($course, 'Intro essay');

        $this->gradeAttempt($course, $quiz, $alice, 9, 10, 90);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        // The untaken essay is a dash for Alice and excluded from both averages.
        $this->assertNull($json['students'][0]['cells']["assignment-{$assignment->id}"]);
        $this->assertSame(90.0, (float) $json['students'][0]['course_percentage']);
        $this->assertNull($json['assessments'][0]['average']);
    }

    public function test_a_column_average_covers_only_graded_students(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $this->enroll($course, 'Bob');

        $quiz = $this->quiz($course, 'Midterm');
        $this->gradeAttempt($course, $quiz, $alice, 9, 10, 90);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        $this->assertSame(90.0, (float) $json['assessments'][0]['average']);

        $bob = collect($json['students'])->firstWhere('name', 'Bob');
        $this->assertNull($bob['course_percentage']);
        $this->assertNull($bob['cells']["quiz-{$quiz->id}"]);
    }

    // ----------------------------------------------------------- no leaks

    public function test_grades_from_another_course_do_not_leak_in(): void
    {
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');
        $this->enroll($otherCourse, 'Alice');

        $quiz = $this->quiz($course, 'Midterm');
        $this->gradeAttempt($course, $quiz, $alice, 9, 10, 90);
        $this->gradeAttempt($otherCourse, $this->quiz($otherCourse, 'Other midterm'), $alice, 3, 10, 30);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        $this->assertCount(1, $json['assessments']);
        $this->assertSame('Midterm', $json['assessments'][0]['title']);
        $this->assertSame(90.0, (float) $json['students'][0]['course_percentage']);
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

    private function quiz(Course $course, string $title): Quiz
    {
        return Quiz::factory()->create(['course_id' => $course->id, 'title' => $title]);
    }

    private function assignment(Course $course, string $title): Assignment
    {
        return Assignment::factory()->create(['course_id' => $course->id, 'title' => $title]);
    }

    private function gradeAttempt(Course $course, Quiz $quiz, User $student, float $score, float $maxScore, float $percentage): void
    {
        $attempt = QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => $score,
            'score_percentage' => $percentage,
            'passed' => $percentage >= 70,
        ]);

        Grade::factory()->create([
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

    private function gradeAssignment(Course $course, Assignment $assignment, User $student, float $score, float $maxScore): void
    {
        $submission = AssignmentSubmission::factory()->graded()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'grade' => $score,
        ]);

        Grade::factory()->create([
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
}
