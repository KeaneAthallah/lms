<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizQuestion>
 */
class QuizQuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'type' => 'multiple_choice',
            'question_text' => fake()->sentence().'?',
            'points' => 1.00,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }
}
