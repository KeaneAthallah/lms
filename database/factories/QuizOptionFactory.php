<?php

namespace Database\Factories;

use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizOption>
 */
class QuizOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_question_id' => QuizQuestion::factory(),
            'option_text' => fake()->sentence(),
            'is_correct' => false,
            'explanation' => null,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_correct' => true,
            'explanation' => fake()->sentence(),
        ]);
    }
}
