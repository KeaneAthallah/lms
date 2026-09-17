<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_courses_are_listed_publicly(): void
    {
        $published = Course::factory()->create();

        Course::factory()->draft()->create();

        $this->getJson('/api/courses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $published->slug);
    }

    public function test_draft_course_is_not_visible_to_guests(): void
    {
        $course = Course::factory()->draft()->create();

        $this->getJson("/api/courses/{$course->slug}")->assertForbidden();
    }

    public function test_course_show_returns_the_curriculum(): void
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->getJson("/api/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $course->slug)
            ->assertJsonPath('data.sections.0.lessons.0.title', $lesson->title)
            ->assertJsonPath('data.instructor.roles.0', 'instructor');
    }

    public function test_active_categories_are_listed(): void
    {
        $category = CourseCategory::factory()->create(['status' => 'active']);

        CourseCategory::factory()->create(['status' => 'inactive']);

        $this->getJson('/api/courses/categories')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', $category->name);
    }
}
