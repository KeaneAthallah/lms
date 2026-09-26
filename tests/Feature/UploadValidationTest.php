<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadValidationTest extends TestCase
{
    use RefreshDatabase;

    private function course(): Course
    {
        return Course::factory()->for(User::factory()->instructor(), 'instructor')->create();
    }

    public function test_lesson_materials_reject_an_executable_extension(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = Lesson::factory()
            ->for(CourseSection::factory()->for($course), 'section')
            ->for($course)
            ->create();

        $this->actingAs($course->instructor)
            ->post("/api/instructor/courses/{$course->slug}/lessons/{$lesson->id}/materials", [
                'file' => UploadedFile::fake()->create('shell.php', 1, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_lesson_materials_reject_html(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = Lesson::factory()
            ->for(CourseSection::factory()->for($course), 'section')
            ->for($course)
            ->create();

        $this->actingAs($course->instructor)
            ->post("/api/instructor/courses/{$course->slug}/lessons/{$lesson->id}/materials", [
                'file' => UploadedFile::fake()->create('page.html', 1, 'text/html'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_lesson_materials_accept_a_pdf(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = Lesson::factory()
            ->for(CourseSection::factory()->for($course), 'section')
            ->for($course)
            ->create();

        $this->actingAs($course->instructor)
            ->post("/api/instructor/courses/{$course->slug}/lessons/{$lesson->id}/materials", [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ])
            ->assertCreated();

        $this->assertDatabaseCount('lesson_materials', 1);
    }

    public function test_assignment_submission_without_a_type_list_rejects_an_unlisted_file(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        // No `allowed_file_types` configured, so the default allowlist applies.
        $assignment = Assignment::factory()->for($course)->create(['allowed_file_types' => null]);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submit", [
                'files' => [UploadedFile::fake()->create('payload.php', 1, 'application/x-php')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.0');
    }

    public function test_assignment_submission_accepts_a_permitted_file(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        $assignment = Assignment::factory()->for($course)->create(['allowed_file_types' => null]);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submit", [
                'content' => 'My answer.',
                'files' => [UploadedFile::fake()->create('essay.pdf', 4, 'application/pdf')],
            ])
            ->assertCreated();
    }

    public function test_an_assignment_cannot_widen_the_allowlist_past_the_forbidden_list(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $student = User::factory()->student()->create();
        Enrollment::factory()->for($student, 'student')->for($course)->create();

        // The assignment explicitly asks for `php`; the global list still wins.
        $assignment = Assignment::factory()->for($course)->create(['allowed_file_types' => ['pdf', 'php']]);

        $this->actingAs($student)
            ->post("/api/assignments/{$assignment->id}/submit", [
                'files' => [UploadedFile::fake()->create('payload.php', 1, 'application/x-php')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.0');
    }
}
