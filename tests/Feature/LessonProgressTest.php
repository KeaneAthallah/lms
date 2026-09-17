<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonProgressTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(int $lessons = 2): array
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);

        $lessonModels = collect(range(1, $lessons))->map(fn (int $order): Lesson => Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => $order,
            'is_published' => true,
        ]));

        return [$course, $lessonModels];
    }

    public function test_completing_all_lessons_completes_the_enrollment(): void
    {
        $student = User::factory()->student()->create();

        [$course, $lessons] = $this->makeCourse();

        (new EnrollmentService)->enroll($student, $course);

        foreach ($lessons as $lesson) {
            $this->actingAs($student)
                ->patchJson("/api/lessons/{$lesson->id}/progress", ['complete' => true])
                ->assertOk()
                ->assertJsonPath('lesson_progress.completed', true);
        }

        $enrollment = Enrollment::where('student_id', $student->id)->where('course_id', $course->id)->first();

        $this->assertSame(100, $enrollment->progress_percent);
        $this->assertSame('completed', $enrollment->status->value);
        $this->assertNotNull($enrollment->completed_at);
    }

    public function test_video_lesson_is_completed_at_the_watch_threshold(): void
    {
        $student = User::factory()->student()->create();

        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->video()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'duration_seconds' => 100,
            'is_published' => true,
        ]);

        (new EnrollmentService)->enroll($student, $course);

        $this->actingAs($student)
            ->patchJson("/api/lessons/{$lesson->id}/progress", ['video_position' => 89])
            ->assertOk()
            ->assertJsonPath('lesson_progress.completed', false)
            ->assertJsonPath('course_progress', 0);

        $this->actingAs($student)
            ->patchJson("/api/lessons/{$lesson->id}/progress", ['video_position' => 90])
            ->assertOk()
            ->assertJsonPath('lesson_progress.completed', true)
            ->assertJsonPath('course_progress', 100);
    }

    public function test_unenrolled_student_cannot_mark_progress(): void
    {
        $student = User::factory()->student()->create();

        [$course, $lessons] = $this->makeCourse();

        $this->actingAs($student)
            ->patchJson("/api/lessons/{$lessons->first()->id}/progress", ['complete' => true])
            ->assertForbidden();
    }
}
