<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'student_id' => User::factory()->student(),
            'status' => 'in_progress',
            'started_at' => now(),
            'submitted_at' => null,
            'score' => null,
            'score_percentage' => null,
            'passed' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'submitted_at' => now(),
            'score' => fake()->randomFloat(2, 0, 100),
            'score_percentage' => fake()->randomFloat(2, 0, 100),
            'passed' => fake()->boolean(),
        ]);
    }
}
