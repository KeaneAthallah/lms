<?php

namespace Tests\Feature;

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
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_is_empty_for_a_student_with_no_evidence(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->getJson('/api/portfolio')
            ->assertOk()
            ->assertJsonPath('data.summary.certificates_count', 0)
            ->assertJsonPath('data.summary.courses_completed', 0)
            ->assertJsonPath('data.summary.concepts_mastered', 0)
            ->assertJsonPath('data.summary.learning_minutes_total', 0)
            ->assertJsonCount(0, 'data.certificates')
            ->assertJsonCount(0, 'data.recent_grades')
            ->assertJsonCount(0, 'data.skills');
    }

    public function test_portfolio_aggregates_real_evidence(): void
    {
        $student = User::factory()->student()->create();

        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $quiz = Quiz::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'quiz_id' => $quiz->id,
            'type' => 'quiz',
            'sort_order' => 1,
            'is_published' => true,
            'duration_seconds' => 600,
        ]);

        (new EnrollmentService)->enroll($student, $course);
        LessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'progress_percent' => 100,
            'completed_at' => now(),
        ]);

        QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 9.00,
            'score_percentage' => 90.00,
            'passed' => true,
            'submitted_at' => now(),
            'started_at' => now()->subMinute(),
        ]);

        Certificate::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'issued_at' => now(),
        ]);

        $this->actingAs($student)
            ->getJson('/api/portfolio')
            ->assertOk()
            ->assertJsonPath('data.summary.certificates_count', 1)
            ->assertJsonPath('data.summary.courses_completed', 1)
            ->assertJsonPath('data.summary.concepts_mastered', 1)
            ->assertJsonPath('data.summary.overall_mastery_percent', 94)
            ->assertJsonPath('data.summary.learning_minutes_total', 11)
            ->assertJsonPath('data.skills.0.status', 'mastered')
            ->assertJsonPath('data.certificates.0.course.id', $course->id)
            ->assertJsonPath('data.courses.0.course.id', $course->id);
    }

    public function test_portfolio_never_leaks_other_students_evidence(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();

        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();

        (new EnrollmentService)->enroll($studentA, $courseA);
        (new EnrollmentService)->enroll($studentB, $courseB);
        Certificate::factory()->create(['student_id' => $studentA->id, 'course_id' => $courseA->id, 'issued_at' => now()]);

        $this->actingAs($studentB)
            ->getJson('/api/portfolio')
            ->assertOk()
            ->assertJsonPath('data.summary.certificates_count', 0)
            ->assertJsonPath('data.courses.0.course.id', $courseB->id)
            ->assertJsonCount(0, 'data.certificates');
    }

    public function test_portfolio_requires_authentication(): void
    {
        $this->getJson('/api/portfolio')->assertStatus(401);
    }
}
