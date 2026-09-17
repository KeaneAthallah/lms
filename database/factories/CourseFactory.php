<?php

namespace Database\Factories;

use App\CourseStatus;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'instructor_id' => User::factory()->instructor(),
            'category_id' => CourseCategory::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraphs(3, true),
            'level' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'language' => 'en',
            'duration_minutes' => fake()->numberBetween(60, 1200),
            'status' => CourseStatus::Published,
            'price' => fake()->randomElement([null, 19.99, 49.99, 99.99]),
            'learning_objectives' => [fake()->sentence(), fake()->sentence(), fake()->sentence()],
            'requirements' => [fake()->sentence(), fake()->sentence()],
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CourseStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CourseStatus::Archived,
        ]);
    }

    public function withInstructor(User $instructor): static
    {
        return $this->state(fn (array $attributes) => [
            'instructor_id' => $instructor->id,
        ]);
    }
}
