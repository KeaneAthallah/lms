<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\QuestionBank;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionBank>
 */
class QuestionBankFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
