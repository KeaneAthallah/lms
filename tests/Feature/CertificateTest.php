<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private function completeCourse(User $student, int $lessons = 2): array
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);

        $lessonsModels = collect(range(1, $lessons))->map(fn (int $order): Lesson => Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => $order,
            'is_published' => true,
        ]));

        (new EnrollmentService)->enroll($student, $course);

        foreach ($lessonsModels as $lesson) {
            app(ProgressService::class)->completeLesson($lesson, $student);
        }

        return ['course' => $course, 'lessons' => $lessonsModels];
    }

    public function test_certificate_is_issued_when_all_lessons_are_completed(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $enrollment = Enrollment::where('student_id', $student->id)->where('course_id', $course->id)->first();

        $this->assertSame('completed', $enrollment->status->value);
        $this->assertDatabaseHas('certificates', [
            'student_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->first();
        $this->assertStringStartsWith('LMS-', $certificate->certificate_number);
        $this->assertNotNull($certificate->identifier);
    }

    public function test_certificate_is_not_duplicated_for_repeated_progress_updates(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course, 'lessons' => $lessons] = $this->completeCourse($student);

        app(ProgressService::class)->completeLesson($lessons->first(), $student);

        $this->assertSame(1, Certificate::where('student_id', $student->id)->where('course_id', $course->id)->count());
    }

    public function test_public_verification_page_renders(): void
    {
        $student = User::factory()->student()->create();

        ['course' => $course] = $this->completeCourse($student);

        $certificate = Certificate::where('student_id', $student->id)->where('course_id', $course->id)->firstOrFail();

        $this->get("/certificates/verify/{$certificate->identifier}")
            ->assertOk()
            ->assertSee($student->name);
    }

    public function test_unknown_identifier_shows_not_found(): void
    {
        $this->get('/certificates/verify/not-a-real-identifier')->assertNotFound();
    }
}
