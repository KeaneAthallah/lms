<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
