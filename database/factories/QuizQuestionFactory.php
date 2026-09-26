<?php

namespace Database\Factories;

use App\Models\QuestionBank;
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
            'question_bank_id' => null,
            'type' => 'multiple_choice',
            'question_text' => fake()->sentence().'?',
            'points' => 1.00,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    /**
     * A question that belongs to a bank instead of a quiz.
     *
     * `quiz_id` is cleared explicitly because the model asserts that exactly one
     * of the two owners is set, and the default state sets the other.
     */
    public function forBank(?QuestionBank $bank = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'quiz_id' => null,
            'question_bank_id' => $bank?->id ?? QuestionBank::factory(),
        ]);
    }
}
