<?php

namespace Tests\Feature;

use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_instructor_dashboard_reports_real_activity(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->withInstructor($instructor)->create();
        $student = User::factory()->student()->create();

        Enrollment::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $assignment = $course->assignments()->create([
            'title' => 'Reflection essay',
            'instructions' => 'Write a reflection.',
            'max_score' => 100,
            'status' => 'active',
        ]);

        AssignmentSubmission::factory()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
        ]);

        $quiz = Quiz::factory()->create(['course_id' => $course->id]);

        QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
        ]);

        $this->actingAs($instructor)
            ->getJson('/api/instructor/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.total_courses', 1)
            ->assertJsonPath('stats.published_courses', 1)
            ->assertJsonPath('stats.total_students', 1)
            ->assertJsonPath('stats.total_enrollments', 1)
            ->assertJsonPath('stats.pending_assignments', 1)
            ->assertJsonCount(1, 'pending_submissions')
            ->assertJsonCount(1, 'recent_quiz_activity')
            ->assertJsonCount(1, 'recent_enrollments')
            ->assertJsonCount(1, 'course_stats');
    }

    public function test_instructor_dashboard_handles_empty_account(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->getJson('/api/instructor/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.total_courses', 0)
            ->assertJsonPath('stats.total_students', 0);
    }
}
