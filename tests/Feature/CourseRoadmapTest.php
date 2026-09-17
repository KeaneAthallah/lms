<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CourseRoadmapTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_relations_link_prerequisites_and_unlocks(): void
    {
        $intro = Course::factory()->create();
        $advanced = Course::factory()->create();
        $intermediate = Course::factory()->create();

        CoursePrerequisite::create([
            'course_id' => $intermediate->id,
            'prerequisite_course_id' => $intro->id,
            'sort_order' => 1,
        ]);

        $this->assertTrue($intermediate->prerequisiteCourses->contains($intro));
        $this->assertTrue($intro->unlocks->contains($intermediate));
        $this->assertFalse($advanced->unlocks->contains($intermediate));
    }

    public function test_roadmap_reports_mixed_states(): void
    {
        $student = User::factory()->student()->create();
        $started = Course::factory()->create();
        $untouched = Course::factory()->create();
        $current = Course::factory()->create();

        CoursePrerequisite::create(['course_id' => $current->id, 'prerequisite_course_id' => $started->id, 'sort_order' => 1]);
        CoursePrerequisite::create(['course_id' => $current->id, 'prerequisite_course_id' => $untouched->id, 'sort_order' => 2]);

        (new EnrollmentService)->enroll($student, $started);
        $started->enrollments()->where('student_id', $student->id)->first()->update(['progress_percent' => 55]);

        $response = $this->actingAs($student)
            ->getJson("/api/courses/{$current->slug}/roadmap")
            ->assertOk()
            ->assertJsonPath('data.method', 'deterministic_rules')
            ->assertJsonPath('data.course.id', $current->id)
            ->assertJsonCount(2, 'data.prerequisites')
            ->assertJsonPath('data.state', 'in_progress')
            ->assertJsonPath('data.prerequisites.0.state', 'in_progress')
            ->assertJsonPath('data.prerequisites.0.progress_percent', 55)
            ->assertJsonPath('data.prerequisites.1.state', 'not_started');

        $this->assertSame('Continue prerequisite', $response->json('data.prerequisites.0.cta.label'));
    }

    public function test_roadmap_reports_ready_when_all_prerequisites_are_completed_with_certificates(): void
    {
        $student = User::factory()->student()->create();
        $intro = Course::factory()->create();
        $current = Course::factory()->create();

        CoursePrerequisite::create(['course_id' => $current->id, 'prerequisite_course_id' => $intro->id, 'sort_order' => 1]);

        Certificate::factory()->create([
            'student_id' => $student->id,
            'course_id' => $intro->id,
            'issued_at' => now(),
        ]);

        $this->actingAs($student)
            ->getJson("/api/courses/{$current->slug}/roadmap")
            ->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.prerequisites.0.state', 'completed')
            ->assertJsonPath('data.prerequisites.0.progress_percent', 100);
    }

    public function test_prerequisites_are_informational_and_never_block_enrollment(): void
    {
        $student = User::factory()->student()->create();
        $intro = Course::factory()->create();
        $current = Course::factory()->create();

        CoursePrerequisite::create(['course_id' => $current->id, 'prerequisite_course_id' => $intro->id, 'sort_order' => 1]);

        $this->actingAs($student)
            ->getJson("/api/courses/{$current->slug}/roadmap")
            ->assertOk()
            ->assertJsonPath('data.state', 'not_started');

        try {
            (new EnrollmentService)->enroll($student, $current);
            $this->assertSame(1, $current->enrollments()->where('student_id', $student->id)->count());
        } catch (ValidationException $exception) {
            $this->fail('Enrollment was blocked by an unfinished prerequisite: '.$exception->getMessage());
        }
    }
}
