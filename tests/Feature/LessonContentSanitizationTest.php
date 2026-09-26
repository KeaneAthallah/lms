<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonContentSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private function course(): Course
    {
        return Course::factory()->for(User::factory()->instructor(), 'instructor')->create();
    }

    private function createLesson(string $content): Lesson
    {
        $course = $this->course();
        $section = CourseSection::factory()->for($course)->create();

        $this->actingAs($course->instructor)
            ->post("/api/instructor/courses/{$course->slug}/sections/{$section->id}/lessons", [
                'title' => 'Sanitised lesson',
                'type' => 'text',
                'content' => $content,
            ])
            ->assertCreated();

        return Lesson::where('title', 'Sanitised lesson')->firstOrFail();
    }

    public function test_script_tags_are_stripped(): void
    {
        $lesson = $this->createLesson('<p>Before</p><script>alert(document.cookie)</script><p>After</p>');

        $this->assertStringNotContainsString('<script', (string) $lesson->content);
        $this->assertStringNotContainsString('alert(', (string) $lesson->content);
        $this->assertStringContainsString('<p>Before</p>', (string) $lesson->content);
        $this->assertStringContainsString('<p>After</p>', (string) $lesson->content);
    }

    public function test_event_handler_attributes_are_stripped(): void
    {
        $lesson = $this->createLesson('<p onclick="steal()" onmouseover="x()">Click me</p>');

        $this->assertStringNotContainsString('onclick', (string) $lesson->content);
        $this->assertStringNotContainsString('onmouseover', (string) $lesson->content);
        $this->assertStringContainsString('Click me', (string) $lesson->content);
    }

    public function test_javascript_urls_are_stripped(): void
    {
        $lesson = $this->createLesson('<a href="javascript:alert(1)">Click</a>');

        $this->assertStringNotContainsString('javascript:', (string) $lesson->content);
    }

    public function test_iframes_and_forms_are_removed(): void
    {
        $lesson = $this->createLesson('<iframe src="https://evil.example"></iframe><form action="/x"><input></form><p>Kept</p>');

        $this->assertStringNotContainsString('<iframe', (string) $lesson->content);
        $this->assertStringNotContainsString('<form', (string) $lesson->content);
        $this->assertStringContainsString('<p>Kept</p>', (string) $lesson->content);
    }

    public function test_unknown_elements_are_unwrapped_but_their_text_survives(): void
    {
        $lesson = $this->createLesson('<marquee>Important notice</marquee>');

        $this->assertStringNotContainsString('<marquee', (string) $lesson->content);
        $this->assertStringContainsString('Important notice', (string) $lesson->content);
    }

    public function test_safe_formatting_is_preserved(): void
    {
        $lesson = $this->createLesson('<h2>Topic</h2><ul><li>One</li></ul><p><strong>Bold</strong> and <em>italic</em></p>');

        $this->assertStringContainsString('<h2>Topic</h2>', (string) $lesson->content);
        $this->assertStringContainsString('<li>One</li>', (string) $lesson->content);
        $this->assertStringContainsString('<strong>Bold</strong>', (string) $lesson->content);
    }

    public function test_links_open_safely(): void
    {
        $lesson = $this->createLesson('<a href="https://example.com" target="_blank">Docs</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', (string) $lesson->content);
    }

    public function test_sanitizer_leaves_plain_text_untouched(): void
    {
        $this->assertSame('Just words.', HtmlSanitizer::clean('Just words.'));
        $this->assertNull(HtmlSanitizer::clean(null));
        $this->assertNull(HtmlSanitizer::clean('   '));
    }

    public function test_sanitizer_rejects_data_urls(): void
    {
        $result = HtmlSanitizer::clean('<img src="data:text/html;base64,PHNjcmlwdD4=">');

        $this->assertStringNotContainsString('data:', (string) $result);
    }

    public function test_sanitizer_keeps_site_relative_urls(): void
    {
        $result = HtmlSanitizer::clean('<a href="/courses/1">Course</a>');

        $this->assertStringContainsString('href="/courses/1"', (string) $result);
    }
}
