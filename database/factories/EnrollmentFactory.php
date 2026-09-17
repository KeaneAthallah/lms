<?php

namespace Database\Factories;

use App\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student(),
            'course_id' => Course::factory(),
            'status' => EnrollmentStatus::Active,
            'progress_percent' => fake()->numberBetween(0, 90),
            'enrolled_at' => now(),
            'last_accessed_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EnrollmentStatus::Completed,
            'progress_percent' => 100,
            'completed_at' => now(),
        ]);
    }
}
