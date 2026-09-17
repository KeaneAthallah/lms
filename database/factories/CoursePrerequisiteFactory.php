<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CoursePrerequisite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoursePrerequisite>
 */
class CoursePrerequisiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'prerequisite_course_id' => Course::factory(),
            'sort_order' => 1,
        ];
    }
}
