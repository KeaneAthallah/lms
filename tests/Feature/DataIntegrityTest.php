<?php

namespace Tests\Feature;

use App\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\EnrollmentService;
use App\Services\QuizService;
use App\SubmissionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function course(): Course
    {
        return Course::factory()->for(User::factory()->instructor(), 'instructor')->create();
    }

    public function test_a_student_cannot_hold_two_enrollments_for_the_same_course(): void
    {
        $course = $this->course();
        $student = User::factory()->student()->create();

        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $this->expectException(QueryException::class);

        Enrollment::factory()->for($student, 'student')->for($course)->create();
    }

    public function test_enrolling_twice_reports_a_validation_error_rather_than_a_server_error(): void
    {
        $course = $this->course();
        $student = User::factory()->student()->create();

        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $this->actingAs($student)
            ->postJson("/api/courses/{$course->slug}/enroll")
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrollment');
    }

    public function test_a_student_cannot_hold_two_certificates_for_the_same_course(): void
    {
        $course = $this->course();
        $student = User::factory()->student()->create();

        Enrollment::factory()->for($student, 'student')->for($course)->completed()->create();

        Certificate::factory()->for($student, 'student')->for($course)->create();

        // The unique index on (student_id, course_id) is the real guarantee that
        // a completion processed twice cannot issue two certificates.
        $this->expectException(QueryException::class);

        Certificate::factory()->for($student, 'student')->for($course)->create();
    }

    public function test_issuing_a_certificate_twice_returns_the_same_certificate(): void
    {
        $course = $this->course();
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student, 'student')->for($course)->completed()->create();

        $service = app(CertificateService::class);

        $first = $service->issue($enrollment);
        $second = $service->issue($enrollment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Certificate::where('student_id', $student->id)
            ->where('course_id', $course->id)->count());
    }

    public function test_certificate_numbers_are_unique(): void
    {
        $course = $this->course();

        foreach (range(1, 3) as $ignored) {
            $student = User::factory()->student()->create();
            $enrollment = Enrollment::factory()->for($student, 'student')->for($course)->completed()->create();

            app(CertificateService::class)->issue($enrollment);
        }

        $numbers = Certificate::pluck('certificate_number');

        $this->assertCount(3, $numbers);
        $this->assertCount(3, $numbers->unique());
    }

    public function test_a_quiz_attempt_cannot_be_submitted_twice(): void
    {
        [$quiz, $attempt, $student] = $this->quizWithAttempt();

        $service = app(QuizService::class);

        $service->submit($attempt, ['questions' => []]);

        $this->expectException(ValidationException::class);

        $service->submit($attempt->fresh(), ['questions' => []]);
    }

    public function test_a_completed_attempt_keeps_its_original_score(): void
    {
        [$quiz, $attempt, $student] = $this->quizWithAttempt();

        $service = app(QuizService::class);

        $service->submit($attempt, ['questions' => []]);

        $score = $attempt->fresh()->score;

        try {
            $service->submit($attempt->fresh(), ['questions' => []]);
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertSame($score, $attempt->fresh()->score);
        $this->assertSame(1, Grade::where('source_type', QuizAttempt::class)
            ->where('source_id', $attempt->id)->count());
    }

    public function test_the_attempt_limit_is_enforced(): void
    {
        [$quiz, $attempt] = $this->quizWithAttempt(['attempts_allowed' => 1]);

        $service = app(QuizService::class);

        $service->submit($attempt, ['questions' => []]);

        $this->expectException(ValidationException::class);

        $service->start($quiz, $attempt->student);
    }

    public function test_regrading_a_submission_keeps_one_ledger_entry(): void
    {
        $course = $this->course();
        $instructor = $course->instructor;
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $assignment = Assignment::factory()->for($course)->create(['max_score' => 50]);

        $submission = $course->assignments()->first()->submissions()->create([
            'student_id' => $student->id,
            'status' => SubmissionStatus::Submitted,
            'content' => 'Answer',
            'files' => [],
        ]);

        $url = "/api/instructor/courses/{$course->slug}/submissions/{$submission->id}/grade";

        $this->actingAs($instructor)->postJson($url, ['grade' => 30, 'feedback' => 'Good'])->assertOk();
        $this->actingAs($instructor)->postJson($url, ['grade' => 40, 'feedback' => 'Better'])->assertOk();

        $this->assertSame(1, Grade::where('source_type', AssignmentSubmission::class)
            ->where('source_id', $submission->id)->count());

        $this->assertSame(40.0, (float) $submission->fresh()->grade);
    }

    public function test_a_grade_cannot_exceed_the_assignment_maximum(): void
    {
        $course = $this->course();
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $assignment = Assignment::factory()->for($course)->create(['max_score' => 50]);
        $submission = $assignment->submissions()->create([
            'student_id' => $student->id,
            'status' => SubmissionStatus::Submitted,
            'content' => 'Answer',
            'files' => [],
        ]);

        $this->actingAs($course->instructor)
            ->postJson("/api/instructor/courses/{$course->slug}/submissions/{$submission->id}/grade", [
                'grade' => 999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('grade');
    }

    /**
     * @return array{0: Quiz, 1: QuizAttempt, 2: User}
     */
    private function quizWithAttempt(array $quizAttributes = []): array
    {
        $course = $this->course();
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $quiz = Quiz::factory()->for($course)->create($quizAttributes + ['passing_score' => 50]);

        $question = QuizQuestion::factory()->for($quiz)->create(['type' => 'multiple_choice', 'points' => 10]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true]);

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return [$quiz->fresh(), $attempt, $student];
    }

    public function test_enrollment_status_enum_is_used_rather_than_a_raw_string(): void
    {
        $course = $this->course();
        $student = User::factory()->student()->create();

        $enrollment = app(EnrollmentService::class)->enroll($student, $course);

        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
    }
}
