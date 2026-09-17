<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'instructions' => fake()->optional()->sentence(),
            'time_limit_minutes' => fake()->optional()->numberBetween(5, 60),
            'passing_score' => 70.00,
            'attempts_allowed' => 2,
            'status' => 'active',
        ];
    }
}
