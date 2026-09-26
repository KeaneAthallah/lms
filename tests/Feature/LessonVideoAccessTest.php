<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LessonVideoAccessTest extends TestCase
{
    use RefreshDatabase;

    private function lessonWithVideo(Course $course, string $body = '0123456789abcdef'): Lesson
    {
        $section = CourseSection::factory()->for($course)->create();

        $path = 'lessons/videos/'.$course->id.'/clip.mp4';
        Storage::disk('local')->put($path, $body);

        return Lesson::factory()->for($section, 'section')->for($course)->create([
            'title' => 'Video lesson',
            'type' => 'video',
            'video_path' => $path,
            'video_disk' => 'local',
            'is_published' => true,
        ]);
    }

    private function course(): Course
    {
        return Course::factory()->for(User::factory()->instructor(), 'instructor')->create();
    }

    public function test_video_is_served_to_an_enrolled_student(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lessonWithVideo($course);

        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $this->actingAs($student)
            ->get("/api/lessons/{$lesson->id}/video")
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_video_is_not_served_to_a_guest(): void
    {
        Storage::fake('local');

        $lesson = $this->lessonWithVideo($this->course());

        $this->get("/api/lessons/{$lesson->id}/video")->assertUnauthorized();
    }

    public function test_video_is_not_served_to_a_student_who_is_not_enrolled(): void
    {
        Storage::fake('local');

        $lesson = $this->lessonWithVideo($this->course());

        // 404, not 403: the lesson must not even be confirmed to exist.
        $this->actingAs(User::factory()->student()->create())
            ->get("/api/lessons/{$lesson->id}/video")
            ->assertNotFound();
    }

    public function test_video_of_an_unpublished_lesson_is_not_served_to_students(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lessonWithVideo($course);
        $lesson->update(['is_published' => false]);

        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $this->actingAs($student)
            ->get("/api/lessons/{$lesson->id}/video")
            ->assertNotFound();
    }

    public function test_video_of_an_unpublished_lesson_is_served_to_the_course_owner(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lessonWithVideo($course);
        $lesson->update(['is_published' => false]);

        $this->actingAs($course->instructor)
            ->get("/api/lessons/{$lesson->id}/video")
            ->assertOk();
    }

    public function test_byte_range_requests_are_answered_with_partial_content(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lessonWithVideo($course, 'abcdefghij');

        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $response = $this->actingAs($student)
            ->get("/api/lessons/{$lesson->id}/video", ['Range' => 'bytes=2-5'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');

        // The body is streamed, so it has to be drained explicitly.
        $this->assertSame('cdef', $response->streamedContent());
    }

    public function test_out_of_range_request_falls_back_to_the_whole_file(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lessonWithVideo($course, 'abcdefghij');

        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $this->actingAs($student)
            ->get("/api/lessons/{$lesson->id}/video", ['Range' => 'bytes=100-200'])
            ->assertOk();
    }

    public function test_uploaded_videos_are_stored_off_the_public_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->create();
        $section = CourseSection::factory()->for($course)->create();

        $response = $this->actingAs($instructor)
            ->post("/api/instructor/courses/{$course->slug}/sections/{$section->id}/lessons", [
                'title' => 'Recorded lecture',
                'type' => 'video',
                'video' => UploadedFile::fake()->create('lecture.mp4', 8, 'video/mp4'),
            ]);

        $response->assertCreated();

        $lesson = Lesson::where('title', 'Recorded lecture')->firstOrFail();

        $this->assertSame('local', $lesson->video_disk);
        $this->assertStringStartsWith("lessons/videos/{$course->id}/", $lesson->video_path);
        $this->assertFalse(Storage::disk('public')->exists($lesson->video_path));
        Storage::disk('local')->assertExists($lesson->video_path);
    }

    public function test_uploaded_video_path_is_scoped_to_the_course(): void
    {
        Storage::fake('local');

        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->create();
        $section = CourseSection::factory()->for($course)->create();

        $this->actingAs($instructor)
            ->post("/api/instructor/courses/{$course->slug}/sections/{$section->id}/lessons", [
                'title' => 'Course scoped lecture',
                'type' => 'video',
                'video' => UploadedFile::fake()->create('lecture.mp4', 4, 'video/mp4'),
            ])
            ->assertCreated();

        $lesson = Lesson::where('title', 'Course scoped lecture')->firstOrFail();

        $this->assertStringStartsWith("lessons/videos/{$course->id}/", $lesson->video_path);
    }

    public function test_the_public_storage_path_is_not_returned_for_a_stored_video(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lessonWithVideo($course);

        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $url = $this->actingAs($student)
            ->getJson("/api/courses/{$course->slug}/learn")
            ->assertOk()
            ->json('course.sections.0.lessons.0.video_url');

        $this->assertSame(route('lessons.video', $lesson), $url);
        $this->assertStringNotContainsString('/storage/', (string) $url);
    }
}
