<?php

namespace Tests\Feature;

use App\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Notifications\AssignmentDeadlineReminder;
use App\Notifications\AssignmentGraded;
use App\Notifications\CertificateIssued;
use App\Notifications\CourseCompleted;
use App\Notifications\EnrollmentConfirmed;
use App\Notifications\NewAssignmentSubmission;
use App\Notifications\NewEnrollment;
use App\Notifications\QuizResult;
use App\Services\EnrollmentService;
use App\Services\ProgressService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pins *who* is told *what*, across every notification the platform sends.
 *
 * There was no coverage of any of this before: the notifications were all
 * dispatched correctly by eye, and nothing would have failed if a dispatch were
 * deleted, pointed at the wrong person, or dropped for one of the six flows.
 * Queueing them (see `NotificationQueueingTest`) is only safe because of these
 * tests -- moving delivery to a worker means a wrong recipient is no longer
 * obvious in a manual pass.
 *
 * `Notification::fake()` records the dispatch, so these tests are about *who*,
 * not about delivery. Whether the work is queued or run inline is the other
 * file's question.
 */
class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrolling_notifies_the_student_and_the_instructor(): void
    {
        Notification::fake();

        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        (new EnrollmentService)->enroll($student, $course);

        Notification::assertSentTo($student, EnrollmentConfirmed::class);
        Notification::assertSentTo($course->instructor, NewEnrollment::class);

        // The mirror image matters as much as the send: a student must not be
        // told they enrolled in somebody else's course.
        Notification::assertNotSentTo($student, NewEnrollment::class);
        Notification::assertNotSentTo($course->instructor, EnrollmentConfirmed::class);
    }

    public function test_completing_a_course_notifies_the_student_of_both_the_completion_and_the_certificate(): void
    {
        Notification::fake();

        $student = User::factory()->student()->create();
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'is_published' => true,
        ]);

        (new EnrollmentService)->enroll($student, $course);
        app(ProgressService::class)->completeLesson($lesson, $student);

        Notification::assertSentTo($student, CourseCompleted::class);
        Notification::assertSentTo($student, CertificateIssued::class);
        Notification::assertSentToTimes($student, CourseCompleted::class, 1);
        Notification::assertSentToTimes($student, CertificateIssued::class, 1);
    }

    public function test_a_second_completion_does_not_re_notify_the_student(): void
    {
        Notification::fake();

        $student = User::factory()->student()->create();
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'is_published' => true,
        ]);

        (new EnrollmentService)->enroll($student, $course);
        $progress = app(ProgressService::class);
        $progress->completeLesson($lesson, $student);
        $progress->completeLesson($lesson, $student);

        // The certificate is a database invariant, and a duplicate mail would
        // tell the student their course finished twice.
        Notification::assertSentToTimes($student, CertificateIssued::class, 1);
    }

    public function test_submitting_a_quiz_notifies_the_student_of_the_result(): void
    {
        Notification::fake();

        $student = User::factory()->student()->create();
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'type' => 'quiz',
            'is_published' => true,
        ]);
        $quiz = Quiz::factory()->create([
            'course_id' => $course->id,
            'status' => 'active',
            'attempts_allowed' => 2,
        ]);
        $lesson->update(['quiz_id' => $quiz->id]);

        $question = QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'type' => 'multiple_choice', 'points' => 1, 'sort_order' => 1]);
        $correct = QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => true]);
        QuizOption::factory()->create(['quiz_question_id' => $question->id, 'is_correct' => false]);

        (new EnrollmentService)->enroll($student, $course);

        $attempt = $this->actingAs($student)
            ->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertCreated()
            ->json('attempt');

        $this->actingAs($student)
            ->postJson("/api/quiz-attempts/{$attempt['id']}/submit", [
                'questions' => [['question_id' => $question->id, 'answer' => $correct->id]],
            ])
            ->assertOk();

        Notification::assertSentTo($student, QuizResult::class);
        Notification::assertNotSentTo($course->instructor, QuizResult::class);
    }

    public function test_submitting_an_assignment_notifies_the_instructor(): void
    {
        Notification::fake();

        $course = Course::factory()->create();
        $instructor = $course->instructor;
        $student = User::factory()->student()->create();
        $assignment = Assignment::factory()->create(['course_id' => $course->id]);

        (new EnrollmentService)->enroll($student, $course);

        $this->actingAs($student)
            ->postJson("/api/assignments/{$assignment->id}/submit", [
                'content' => 'Here is my work.',
                'files' => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')],
            ])
            ->assertCreated();

        Notification::assertSentTo($instructor, NewAssignmentSubmission::class);
        Notification::assertNotSentTo($student, NewAssignmentSubmission::class);
    }

    public function test_grading_an_assignment_notifies_the_student(): void
    {
        Notification::fake();

        $course = Course::factory()->create();
        $instructor = $course->instructor;
        $student = User::factory()->student()->create();
        $assignment = Assignment::factory()->create(['course_id' => $course->id]);

        (new EnrollmentService)->enroll($student, $course);

        $submission = $this->actingAs($student)
            ->postJson("/api/assignments/{$assignment->id}/submit", ['content' => 'My work.'])
            ->assertCreated()
            ->json('submission');

        $this->actingAs($instructor)
            ->postJson("/api/instructor/courses/{$course->slug}/submissions/{$submission['id']}/grade", [
                'grade' => 88,
                'feedback' => 'Strong work.',
            ])
            ->assertOk();

        Notification::assertSentTo($student, AssignmentGraded::class);
        Notification::assertNotSentTo($instructor, AssignmentGraded::class);
    }

    public function test_the_deadline_reminder_reaches_only_students_who_have_not_submitted(): void
    {
        Notification::fake();

        $course = Course::factory()->create();
        $assignment = Assignment::factory()->create([
            'course_id' => $course->id,
            'status' => 'active',
            'due_at' => now()->addHours(2),
        ]);

        $submitted = User::factory()->student()->create();
        $waiting = User::factory()->student()->create();

        (new EnrollmentService)->enroll($submitted, $course);
        (new EnrollmentService)->enroll($waiting, $course);

        AssignmentSubmission::factory()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $submitted->id,
        ]);

        $this->artisan('lms:send-assignment-reminders')
            ->expectsOutputToContain('Sent 1 assignment reminders.')
            ->assertSuccessful();

        Notification::assertSentTo($waiting, AssignmentDeadlineReminder::class);
        Notification::assertNotSentTo($submitted, AssignmentDeadlineReminder::class);
    }

    public function test_the_reminder_command_ignores_assignments_that_are_not_due_soon(): void
    {
        Notification::fake();

        $course = Course::factory()->create();
        Assignment::factory()->create([
            'course_id' => $course->id,
            'status' => 'active',
            'due_at' => now()->addDays(30),
        ]);

        $student = User::factory()->student()->create();
        (new EnrollmentService)->enroll($student, $course);

        $this->artisan('lms:send-assignment-reminders')
            ->expectsOutputToContain('Sent 0 assignment reminders.')
            ->assertSuccessful();

        Notification::assertNotSentTo($student, AssignmentDeadlineReminder::class);
    }

    public function test_the_reminder_command_skips_a_course_with_no_due_assignments(): void
    {
        Notification::fake();

        $student = User::factory()->student()->create();
        $course = Course::factory()->create();
        (new EnrollmentService)->enroll($student, $course);

        // No assignment at all: the command must return before it builds the
        // submission lookup, rather than issuing a query against an empty id
        // list and reporting a reminder run that never happened.
        $this->artisan('lms:send-assignment-reminders')
            ->expectsOutputToContain('Sent 0 assignment reminders.')
            ->assertSuccessful();

        Notification::assertNotSentTo($student, AssignmentDeadlineReminder::class);
    }

    public function test_every_notification_is_queued(): void
    {
        $notifications = glob(app_path('Notifications/*.php'));
        $this->assertNotEmpty($notifications);

        $sync = [];

        foreach ($notifications as $path) {
            $class = 'App\\Notifications\\'.basename($path, '.php');
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (! $reflection->implementsInterface(ShouldQueue::class)) {
                $sync[] = $reflection->getShortName();
            }
        }

        // A notification that forgets `ShouldQueue` goes straight back to
        // blocking the request that triggered it, and nothing else in the suite
        // would notice. This is the guard for the next one that gets added.
        $this->assertSame([], $sync, 'These notifications still send inside the request: '.implode(', ', $sync));
    }

    public function test_a_student_is_not_notified_about_a_course_they_are_not_enrolled_in(): void
    {
        Notification::fake();

        $course = Course::factory()->create();
        $student = User::factory()->student()->create();
        $assignment = Assignment::factory()->create([
            'course_id' => $course->id,
            'status' => 'active',
            'due_at' => now()->addHours(2),
        ]);

        // Deliberately not enrolled: the reminder must come from the course's
        // enrollments, not from every student in the system.
        (new EnrollmentService)->enroll(User::factory()->student()->create(), $course);

        $this->artisan('lms:send-assignment-reminders')->assertSuccessful();

        Notification::assertNotSentTo($student, AssignmentDeadlineReminder::class);
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        $this->assertSame(EnrollmentStatus::Active, $course->enrollments()->first()->status);
    }
}
