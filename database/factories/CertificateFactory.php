<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student(),
            'course_id' => Course::factory(),
            'enrollment_id' => null,
            'certificate_number' => 'LMS-'.fake()->unique()->numberBetween(100000, 999999),
            'identifier' => Str::uuid()->toString(),
            'issued_at' => now(),
        ];
    }
}
