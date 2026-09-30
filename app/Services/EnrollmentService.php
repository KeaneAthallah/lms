<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\EnrollmentConfirmed;
use App\Notifications\NewEnrollment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    public function enroll(User $student, Course $course): Enrollment
    {
        if (! $course->isPublished()) {
            throw ValidationException::withMessages([
                'enrollment' => ['This course is not available for enrollment.'],
            ]);
        }

        if (Enrollment::where('student_id', $student->id)->where('course_id', $course->id)->exists()) {
            throw ValidationException::withMessages([
                'enrollment' => ['You are already enrolled in this course.'],
            ]);
        }

        try {
            // The unique (student_id, course_id) index is the real guard against
            // a double submit. The pre-check above only produces a friendly
            // message; the unique violation is what actually prevents the row.
            $enrollment = DB::transaction(fn (): Enrollment => Enrollment::create([
                'student_id' => $student->id,
                'course_id' => $course->id,
                'status' => EnrollmentStatus::Active,
                'progress_percent' => 0,
                'enrolled_at' => now(),
            ]));
        } catch (QueryException $exception) {
            if (! $this->isDuplicateEnrollment($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'enrollment' => ['You are already enrolled in this course.'],
            ]);
        }

        $student->notify((new EnrollmentConfirmed($course))->afterCommit());
        $course->instructor->notify((new NewEnrollment($enrollment))->afterCommit());

        return $enrollment;
    }

    private function isDuplicateEnrollment(QueryException $exception): bool
    {
        // 23000/23505: integrity constraint violation (MySQL / PostgreSQL).
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
