<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentSubmission>
 */
class AssignmentSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'student_id' => User::factory()->student(),
            'content' => fake()->paragraph(),
            'files' => null,
            'version' => 1,
            'status' => 'submitted',
            'submitted_at' => now(),
        ];
    }

    public function graded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'graded',
            'grade' => fake()->randomFloat(2, 50, 100),
            'feedback' => fake()->sentence(),
            'graded_by' => User::factory()->instructor(),
            'graded_at' => now(),
        ]);
    }
}
