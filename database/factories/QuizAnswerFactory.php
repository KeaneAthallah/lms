<?php

namespace Database\Factories;

use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAnswer>
 */
class QuizAnswerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_attempt_id' => QuizAttempt::factory(),
            'quiz_question_id' => QuizQuestion::factory(),
            'answer' => fake()->sentence(),
            'is_correct' => fake()->boolean(),
            'points_earned' => fake()->randomFloat(2, 0, 1),
        ];
    }
}
