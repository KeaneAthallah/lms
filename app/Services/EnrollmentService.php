<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\EnrollmentConfirmed;
use App\Notifications\NewEnrollment;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    public function enroll(User $student, Course $course): Enrollment
    {
        $existing = Enrollment::where('student_id', $student->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'enrollment' => ['You are already enrolled in this course.'],
            ]);
        }

        if (! $course->isPublished()) {
            throw ValidationException::withMessages([
                'enrollment' => ['This course is not available for enrollment.'],
            ]);
        }

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percent' => 0,
            'enrolled_at' => now(),
        ]);

        $student->notify(new EnrollmentConfirmed($course));
        $course->instructor->notify(new NewEnrollment($enrollment));

        return $enrollment;
    }
}
