<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonProgress>
 */
class LessonProgressFactory extends Factory
{
    public function definition(): array
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id]);

        return [
            'student_id' => User::factory()->student(),
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'progress_percent' => fake()->numberBetween(0, 100),
            'video_position_seconds' => 0,
            'started_at' => now()->subHour(),
            'last_accessed_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'progress_percent' => 100,
            'completed_at' => now(),
        ]);
    }
}
