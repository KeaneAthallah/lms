<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstructorRadarTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(User $instructor, User $student): array
    {
        $course = Course::factory()->withInstructor($instructor)->create();
        (new EnrollmentService)->enroll($student, $course);

        return [$course, $student];
    }

    public function test_radar_lists_enrolled_students_with_neutral_flags(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course, $enrolled] = $this->makeCourse($instructor, $student);

        $response = $this->actingAs($instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/radar")
            ->assertOk()
            ->assertJsonPath('data.method', 'deterministic_rules')
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.students.0.student.id', $student->id);

        $flags = collect($response->json('data.students.0.flags'));

        $this->assertTrue(
            $flags->contains(fn (array $flag) => $flag['label'] === 'Low recent activity'),
            'Expected a low activity flag for an idle student that just enrolled.'
        );
    }

    public function test_strong_progress_flag_uses_neutral_evidence(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course, $enrolled] = $this->makeCourse($instructor, $student);

        Certificate::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/radar")
            ->assertOk();

        $flags = collect($response->json('data.students.0.flags'));

        $this->assertTrue(
            $flags->contains(fn (array $flag) => $flag['label'] === 'Strong progress' && str_contains($flag['evidence'], 'certificate')),
            'A certified student should be flagged with neutral certificate evidence.'
        );
    }

    public function test_radar_is_forbidden_for_non_owner_instructors(): void
    {
        $owner = User::factory()->instructor()->create();
        $intruder = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course, $enrolled] = $this->makeCourse($owner, $student);

        $this->actingAs($intruder)
            ->getJson("/api/instructor/courses/{$course->slug}/radar")
            ->assertForbidden();
    }

    public function test_radar_is_forbidden_for_students(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course, $enrolled] = $this->makeCourse($instructor, $student);

        $this->actingAs($student)
            ->getJson("/api/instructor/courses/{$course->slug}/radar")
            ->assertForbidden();
    }

    public function test_radar_requires_authentication(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->withInstructor($instructor)->create();

        $this->getJson("/api/instructor/courses/{$course->slug}/radar")->assertStatus(401);
    }

    public function test_the_stats_are_counted_from_the_record_not_guessed(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $lessons = collect(range(1, 4))->map(fn (int $order): Lesson => Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => $order,
        ]));

        $lessons->take(3)->each(fn (Lesson $lesson) => LessonProgress::factory()->completed()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
        ]));
        LessonProgress::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lessons->last()->id,
        ]);

        $quiz = $this->quiz($course, 'Midterm');
        $this->attempt($student, $quiz, 40);
        $this->attempt($student, $quiz, 91.5);

        $this->submission($this->assignment($course, 'Essay'), $student, 55);

        $stats = $this->radar($course)['students'][0]['stats'];

        $this->assertSame(3, $stats['completed_lessons'], 'An untouched lesson is not a completed one.');
        $this->assertSame(4, $stats['total_lessons']);
        $this->assertSame(2, $stats['quiz_attempts_count']);
        $this->assertSame(91.5, $stats['best_quiz_percentage'], 'The best attempt, not the last or the mean.');
        $this->assertSame(1, $stats['graded_assignments']);
        $this->assertFalse($stats['has_certificate']);
    }

    public function test_the_best_result_below_passing_is_flagged_and_names_the_quiz(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $passing = $this->quiz($course, 'Passing quiz');
        $failing = $this->quiz($course, 'Failed quiz');

        $this->attempt($student, $passing, 80);
        $this->attempt($student, $failing, 40);
        $this->attempt($student, $failing, 55);

        $flag = $this->flagLabelled($course, 'Needs attention');

        $this->assertSame(
            'Best result on quiz "Failed quiz" is below the passing score.',
            $flag['evidence'],
            'One weak quiz is enough, and the evidence has to name it.'
        );
    }

    public function test_two_attempts_below_passing_on_one_quiz_is_repeated_difficulty(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $flaky = $this->quiz($course, 'Flaky quiz');

        $this->attempt($student, $flaky, 30);
        $this->attempt($student, $flaky, 45);

        $flag = $this->flagLabelled($course, 'Repeated assessment difficulty');

        $this->assertSame('Quiz "Flaky quiz" has been attempted 2 times below the passing score.', $flag['evidence']);
    }

    public function test_a_single_attempt_below_passing_is_not_repeated_difficulty(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $this->attempt($student, $this->quiz($course, 'One bad day'), 30);

        $this->assertNull(
            $this->flagLabelled($course, 'Repeated assessment difficulty'),
            'One bad day is not a pattern.'
        );
    }

    public function test_a_low_graded_assignment_is_flagged_and_names_the_assignment(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $this->submission($this->assignment($course, 'Weak essay'), $student, 59);

        $flag = $this->flagLabelled($course, 'Needs attention');

        $this->assertSame('Latest grade on assignment "Weak essay" is below 60%.', $flag['evidence']);
    }

    public function test_an_ungraded_submission_is_not_a_low_grade(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $assignment = $this->assignment($course, 'Not marked yet');
        AssignmentSubmission::factory()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
        ]);

        $this->assertNull(
            $this->flagLabelled($course, 'Needs attention'),
            'A submission with no grade is not a failing grade.'
        );
    }

    public function test_a_low_quiz_outranks_a_low_assignment_when_both_are_present(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $this->attempt($student, $this->quiz($course, 'Failed quiz'), 30);
        $this->submission($this->assignment($course, 'Weak essay'), $student, 10);

        $flags = collect($this->radar($course)['students'][0]['flags'])->where('label', 'Needs attention');

        $this->assertCount(1, $flags, 'The two reasons collapse into one flag, not two.');
        $this->assertStringContainsString('Failed quiz', $flags->first()['evidence']);
    }

    public function test_an_overdue_assignment_without_a_graded_submission_is_flagged(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $this->assignment($course, 'Late work', now()->subDay());

        $flag = $this->flagLabelled($course, 'Assignment overdue');

        $this->assertStringContainsString('Late work', $flag['evidence']);
        $this->assertSame(1, $this->radar($course)['students'][0]['stats']['assignments_overdue']);
    }

    public function test_an_overdue_assignment_that_was_graded_is_not_flagged(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $student);

        $assignment = $this->assignment($course, 'Handed in late', now()->subDay());
        $this->submission($assignment, $student, 88);

        $this->assertNull(
            $this->flagLabelled($course, 'Assignment overdue'),
            'Being late is not the same as not handing it in.'
        );
        $this->assertSame(0, $this->radar($course)['students'][0]['stats']['assignments_overdue']);
    }

    public function test_the_query_count_does_not_grow_with_the_cohort(): void
    {
        $instructor = User::factory()->instructor()->create();
        $first = User::factory()->student()->create();
        [$course] = $this->makeCourse($instructor, $first);

        // Give the course one of each so every aggregate query is exercised on
        // both sides of the comparison, and warm the request so the first-call
        // auth lookups are not counted as cohort cost.
        $this->busyStudent($course, $first);

        $withOneStudent = $this->radarQueryCount($course);

        foreach (range(1, 4) as $ignored) {
            $student = User::factory()->student()->create();
            [$course] = $this->makeCourse($instructor, $student);
            $this->busyStudent($course, $student);
        }

        $this->assertSame(
            $withOneStudent,
            $this->radarQueryCount($course),
            'Each student\'s stats are aggregated in SQL, so a bigger cohort must not mean more queries.'
        );
    }

    // ---------------------------------------------------------------- helpers

    private function busyStudent(Course $course, User $student): void
    {
        $quiz = $this->quiz($course, 'Quiz '.$student->id);

        foreach (range(1, 5) as $ignored) {
            $this->attempt($student, $quiz, 30);
        }

        foreach (range(1, 3) as $ignored) {
            $this->submission($this->assignment($course, 'Assignment '.$student->id), $student, 10);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function radar(Course $course): array
    {
        return $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/radar")
            ->assertOk()
            ->json('data');
    }

    private function radarQueryCount(Course $course): int
    {
        $this->radar($course);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->radar($course);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array{kind: string, label: string, evidence: string}|null
     */
    private function flagLabelled(Course $course, string $label): ?array
    {
        return collect($this->radar($course)['students'][0]['flags'])->firstWhere('label', $label);
    }

    private function quiz(Course $course, string $title): Quiz
    {
        return Quiz::factory()->create(['course_id' => $course->id, 'title' => $title]);
    }

    private function attempt(User $student, Quiz $quiz, float $percentage): QuizAttempt
    {
        return QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => $percentage,
            'score_percentage' => $percentage,
        ]);
    }

    private function assignment(Course $course, string $title, mixed $dueAt = null): Assignment
    {
        return Assignment::factory()->create([
            'course_id' => $course->id,
            'title' => $title,
            'due_at' => $dueAt,
        ]);
    }

    private function submission(Assignment $assignment, User $student, float $grade): AssignmentSubmission
    {
        return AssignmentSubmission::factory()->graded()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'grade' => $grade,
        ]);
    }
}
