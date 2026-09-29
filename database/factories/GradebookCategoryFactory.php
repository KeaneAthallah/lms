<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\GradebookCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradebookCategory>
 */
class GradebookCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'name' => fake()->unique()->randomElement(['Quizzes', 'Assignments', 'Exams', 'Homework', 'Participation', 'Project']),
            'weight' => fake()->randomFloat(2, 10, 40),
            'sort_order' => 0,
        ];
    }
}
