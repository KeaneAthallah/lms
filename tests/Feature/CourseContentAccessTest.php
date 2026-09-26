<?php

namespace Tests\Feature;

use App\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseContentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithLesson(string $title = 'Published course', ?string $body = 'Secret lesson body copy.'): Course
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->create(['title' => $title]);
        $section = CourseSection::factory()->for($course)->create(['sort_order' => 1]);
        Lesson::factory()->for($section, 'section')->for($course)->create([
            'title' => 'Lesson one',
            'content' => $body,
            'video_url' => 'https://cdn.example.com/lesson-1.mp4',
            'is_published' => true,
            'sort_order' => 1,
        ]);

        return $course->fresh();
    }

    public function test_guest_course_detail_does_not_expose_lesson_content(): void
    {
        $course = $this->courseWithLesson();

        $this->getJson("/api/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonMissing(['content' => 'Secret lesson body copy.']);
    }

    public function test_guest_course_detail_does_not_expose_lesson_video_url(): void
    {
        $course = $this->courseWithLesson();

        $this->getJson("/api/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonMissing(['video_url' => 'https://cdn.example.com/lesson-1.mp4']);
    }

    public function test_guest_course_detail_exposes_curriculum_titles_but_not_bodies(): void
    {
        $course = $this->courseWithLesson();

        $response = $this->getJson("/api/courses/{$course->slug}")->assertOk();

        $response->assertJsonPath('data.sections.0.lessons.0.title', 'Lesson one');
        $response->assertJsonPath('data.sections.0.lessons.0.content', null);
        $response->assertJsonPath('data.sections.0.lessons.0.video_url', null);
    }

    public function test_non_enrolled_student_course_detail_does_not_expose_lesson_content(): void
    {
        $course = $this->courseWithLesson();
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->getJson("/api/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonPath('data.sections.0.lessons.0.content', null);
    }

    public function test_enrolled_student_learn_endpoint_exposes_lesson_content(): void
    {
        $course = $this->courseWithLesson();
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        // The learn endpoint resolves the resource inline, so the payload is not
        // wrapped in a `data` key the way the catalogue endpoint wraps it.
        $this->actingAs($student)
            ->getJson("/api/courses/{$course->slug}/learn")
            ->assertOk()
            ->assertJsonPath('course.sections.0.lessons.0.content', 'Secret lesson body copy.');
    }

    public function test_course_owner_course_detail_exposes_lesson_content(): void
    {
        $course = $this->courseWithLesson();

        $this->actingAs($course->instructor)
            ->getJson("/api/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonPath('data.sections.0.lessons.0.content', 'Secret lesson body copy.');
    }

    public function test_unpublished_lesson_is_absent_from_the_curriculum_for_students(): void
    {
        $course = $this->courseWithLesson('Draft lesson course', 'Unpublished body.');
        $this->unpublishedLesson($course);

        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $this->actingAs($student)
            ->getJson("/api/courses/{$course->slug}/learn")
            ->assertOk()
            ->assertJsonMissing(['title' => 'Hidden lesson']);
    }

    public function test_unpublished_lesson_cannot_be_fetched_directly_by_an_enrolled_student(): void
    {
        $course = $this->courseWithLesson('Draft lesson course', 'Unpublished body.');
        $lesson = $this->unpublishedLesson($course);

        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        // 404, not 403: the route binds any lesson id, so a 403 would confirm the
        // lesson exists and let a student walk the id space.
        $this->actingAs($student)
            ->getJson("/api/courses/{$course->slug}/learn/{$lesson->id}")
            ->assertNotFound();
    }

    public function test_course_owner_can_preview_an_unpublished_lesson(): void
    {
        $course = $this->courseWithLesson('Draft lesson course', 'Unpublished body.');
        $lesson = $this->unpublishedLesson($course);

        $this->actingAs($course->instructor)
            ->getJson("/api/courses/{$course->slug}/learn/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('lesson.content', 'Unpublished body.');
    }

    public function test_unpublished_lesson_is_visible_to_the_owner_in_the_catalogue(): void
    {
        $course = $this->courseWithLesson('Draft lesson course', 'Unpublished body.');
        $lesson = $this->unpublishedLesson($course);

        $this->actingAs($course->instructor)
            ->getJson("/api/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonPath('data.sections.0.lessons.1.id', $lesson->id)
            ->assertJsonPath('data.sections.0.lessons.1.content', 'Unpublished body.');
    }

    public function test_cancelled_enrollment_loses_access_to_lesson_content(): void
    {
        $course = $this->courseWithLesson();
        $student = User::factory()->student()->create();

        Enrollment::factory()
            ->for($student, 'student')
            ->for($course)
            ->create(['status' => EnrollmentStatus::Cancelled]);

        $this->actingAs($student)
            ->getJson("/api/courses/{$course->slug}/learn")
            ->assertForbidden();
    }

    public function test_course_owner_may_not_submit_an_assignment_on_their_own_course(): void
    {
        $course = $this->courseWithLesson();
        $assignment = Assignment::factory()->for($course)->create();

        $this->actingAs($course->instructor)
            ->postJson("/api/assignments/{$assignment->id}/submit", [])
            ->assertForbidden();
    }

    public function test_course_owner_may_not_start_a_quiz_attempt_on_their_own_course(): void
    {
        $course = $this->courseWithLesson();
        $quiz = Quiz::factory()->for($course)->create();

        $this->actingAs($course->instructor)
            ->postJson("/api/quizzes/{$quiz->id}/start", [])
            ->assertForbidden();
    }

    public function test_enrolled_student_reaches_assignment_and_quiz_authorization(): void
    {
        $course = $this->courseWithLesson();
        $student = User::factory()->student()->create();

        Enrollment::factory()
            ->for($student, 'student')
            ->for($course)
            ->create(['status' => EnrollmentStatus::Active]);

        $assignment = Assignment::factory()->for($course)->create();
        $quiz = Quiz::factory()->for($course)->create();

        $this->actingAs($student)
            ->getJson("/api/assignments/{$assignment->id}/my-submission")
            ->assertOk();

        $this->actingAs($student)
            ->getJson("/api/quizzes/{$quiz->id}")
            ->assertOk();
    }

    private function unpublishedLesson(Course $course): Lesson
    {
        $section = $course->sections()->orderBy('id')->first();

        return Lesson::factory()->for($section, 'section')->for($course)->create([
            'title' => 'Hidden lesson',
            'content' => 'Unpublished body.',
            'is_published' => false,
            'sort_order' => 2,
        ]);
    }
}
