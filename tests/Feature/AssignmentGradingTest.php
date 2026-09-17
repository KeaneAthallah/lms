<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AssignmentGradingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAssignment(): array
    {
        $course = Course::factory()->create();
        $instructor = $course->instructor;
        $student = User::factory()->student()->create();

        $assignment = Assignment::factory()->create(['course_id' => $course->id]);

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $course->sections()->create([
                'title' => 'Assignments',
                'sort_order' => 1,
            ])->id,
            'type' => 'assignment',
            'assignment_id' => $assignment->id,
            'is_published' => true,
        ]);

        (new EnrollmentService)->enroll($student, $course);

        return compact('course', 'instructor', 'student', 'assignment', 'lesson');
    }

    public function test_student_can_submit_an_assignment(): void
    {
        $a = $this->makeAssignment();

        $this->actingAs($a['student'])
            ->postJson("/api/assignments/{$a['assignment']->id}/submit", [
                'content' => 'Here is my completed work.',
                'files' => [
                    UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Your submission has been saved.')
            ->assertJsonPath('submission.version', 1);

        $this->assertDatabaseHas('assignment_submissions', [
            'assignment_id' => $a['assignment']->id,
            'student_id' => $a['student']->id,
            'status' => 'submitted',
        ]);
    }

    public function test_enrolled_student_can_resubmit_for_a_fresh_grade(): void
    {
        $a = $this->makeAssignment();

        $this->actingAs($a['student'])->postJson("/api/assignments/{$a['assignment']->id}/submit", ['content' => 'First draft.'])->assertStatus(201);

        $this->actingAs($a['student'])
            ->postJson("/api/assignments/{$a['assignment']->id}/submit", ['content' => 'Revised version.'])
            ->assertStatus(201)
            ->assertJsonPath('submission.version', 2);
    }

    public function test_student_cannot_submit_to_another_course_assignment(): void
    {
        $a = $this->makeAssignment();
        $outsider = User::factory()->student()->create();

        $this->actingAs($outsider)
            ->postJson("/api/assignments/{$a['assignment']->id}/submit", ['content' => 'Unauthorized.'])
            ->assertForbidden();
    }

    public function test_instructor_can_grade_a_submission(): void
    {
        $a = $this->makeAssignment();

        $submission = $this->actingAs($a['student'])
            ->postJson("/api/assignments/{$a['assignment']->id}/submit", ['content' => 'My work.'])
            ->json('submission');

        $this->actingAs($a['instructor'])
            ->postJson("/api/instructor/courses/{$a['course']->slug}/submissions/{$submission['id']}/grade", [
                'grade' => 85,
                'feedback' => 'Nice work!',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Submission graded.')
            ->assertJsonPath('submission.status', 'graded')
            ->assertJsonPath('submission.grade', 85);

        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submission['id'],
            'grade' => 85,
            'feedback' => 'Nice work!',
            'status' => 'graded',
        ]);

        $this->assertDatabaseHas('grades', [
            'source_type' => 'App\\Models\\AssignmentSubmission',
            'source_id' => $submission['id'],
            'course_id' => $a['course']->id,
            'student_id' => $a['student']->id,
            'type' => 'assignment',
            'score' => 85,
        ]);

        $progress = LessonProgress::where('student_id', $a['student']->id)->where('lesson_id', $a['lesson']->id)->first();
        $this->assertNotNull($progress);
        $this->assertTrue($progress->isCompleted());
    }
}
