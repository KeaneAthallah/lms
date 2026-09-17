<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Grade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grade>
 */
class GradeFactory extends Factory
{
    public function definition(): array
    {
        $score = fake()->randomFloat(2, 40, 100);

        return [
            'student_id' => User::factory()->student(),
            'course_id' => Course::factory(),
            'type' => 'quiz',
            'score' => $score,
            'max_score' => 100,
            'percentage' => $score,
            'graded_at' => now(),
        ];
    }
}
