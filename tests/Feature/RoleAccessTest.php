<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_access_instructor_endpoints(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)->getJson('/api/instructor/dashboard')->assertForbidden();
    }

    public function test_instructor_can_access_instructor_endpoints(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->getJson('/api/instructor/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.total_courses', 0);
    }

    public function test_instructor_cannot_access_admin_endpoints(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_student_cannot_access_admin_endpoints(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admin_can_access_admin_endpoints(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk();
    }

    public function test_unauthenticated_requests_to_guarded_endpoints_are_rejected(): void
    {
        $this->getJson('/api/instructor/dashboard')->assertStatus(401);
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    public function test_guest_cannot_view_a_draft_course(): void
    {
        $draft = Course::factory()->draft()->create();

        $this->getJson("/api/courses/{$draft->slug}")->assertForbidden();
    }

    public function test_instructor_can_view_and_manage_their_own_draft_course(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->draft()->for($instructor, 'instructor')->create();

        $this->actingAs($instructor)
            ->getJson("/api/courses/{$course->slug}")
            ->assertOk();

        $this->actingAs($instructor)
            ->getJson("/api/instructor/courses/{$course->slug}")
            ->assertOk();
    }

    public function test_instructor_cannot_manage_another_instructors_course(): void
    {
        $instructor = User::factory()->instructor()->create();
        $other = User::factory()->instructor()->create();
        $course = Course::factory()->draft()->for($other, 'instructor')->create();

        $this->actingAs($instructor)
            ->getJson("/api/instructor/courses/{$course->slug}")
            ->assertForbidden();
    }

    public function test_admin_can_view_and_manage_any_draft_course(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->draft()->for($instructor, 'instructor')->create();

        $this->actingAs($admin)
            ->getJson("/api/courses/{$course->slug}")
            ->assertOk();

        $this->actingAs($admin)
            ->getJson("/api/instructor/courses/{$course->slug}")
            ->assertOk();
    }
}
