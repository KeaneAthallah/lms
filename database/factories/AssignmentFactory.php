<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'instructions' => fake()->paragraphs(2, true),
            'due_at' => fake()->optional()->dateTimeBetween('+2 days', '+4 weeks'),
            'max_score' => 100.00,
            'allowed_file_types' => ['pdf', 'doc', 'docx', 'txt'],
            'max_file_size_kb' => 10240,
            'status' => 'active',
        ];
    }
}
