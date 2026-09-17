<?php

namespace Database\Factories;

use App\LessonType;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        $course = Course::factory()->create();

        return [
            'section_id' => CourseSection::factory()->create(['course_id' => $course->id]),
            'course_id' => $course->id,
            'title' => fake()->sentence(4),
            'type' => LessonType::Text,
            'content' => fake()->paragraphs(4, true),
            'is_published' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonType::Video,
            'content' => null,
            'video_path' => fake()->randomElement(['videos/demo.mp4', 'videos/intro.mp4']),
            'duration_seconds' => fake()->numberBetween(120, 1800),
        ]);
    }

    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonType::Document,
            'content' => fake()->paragraphs(2, true),
        ]);
    }
}
