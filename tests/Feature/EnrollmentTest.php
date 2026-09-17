<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_enroll_in_a_published_course(): void
    {
        $student = User::factory()->student()->create();
        $course = Course::factory()->create();

        $this->actingAs($student)
            ->postJson("/api/courses/{$course->slug}/enroll")
            ->assertStatus(201)
            ->assertJsonPath('message', 'You are now enrolled in this course.')
            ->assertJsonPath('enrollment.status', 'active');

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    public function test_duplicate_enrollment_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        $course = Course::factory()->create();

        $this->actingAs($student)->postJson("/api/courses/{$course->slug}/enroll")->assertStatus(201);

        $this->actingAs($student)
            ->postJson("/api/courses/{$course->slug}/enroll")
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrollment');

        $this->assertSame(1, $course->enrollments()->count());
    }

    public function test_student_cannot_enroll_in_a_draft_course(): void
    {
        $student = User::factory()->student()->create();
        $course = Course::factory()->draft()->create();

        $this->actingAs($student)
            ->postJson("/api/courses/{$course->slug}/enroll")
            ->assertForbidden();
    }

    public function test_guest_cannot_enroll(): void
    {
        $course = Course::factory()->create();

        $this->postJson("/api/courses/{$course->slug}/enroll")->assertStatus(401);
    }
}
