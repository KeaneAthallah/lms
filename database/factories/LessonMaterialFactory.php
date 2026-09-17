<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\LessonMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonMaterial>
 */
class LessonMaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'filename' => fake()->word().'.pdf',
            'path' => 'materials/'.fake()->uuid().'.pdf',
            'disk' => 'public',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 5_000_000),
            'type' => 'pdf',
            'is_downloadable' => true,
            'sort_order' => 0,
        ];
    }
}
