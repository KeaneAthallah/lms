<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Notifications\CertificateIssued;
use App\Notifications\CourseCompleted;
use Illuminate\Support\Str;

class CertificateService
{
    /**
     * Issue a certificate if the enrollment meets the course requirements and
     * one does not already exist for this student + course.
     */
    public function issueIfEligible(Enrollment $enrollment): ?Certificate
    {
        if ($enrollment->status !== EnrollmentStatus::Completed) {
            return null;
        }

        if ((bool) config('lms.certificate_requires_passing_quizzes')) {
            foreach ($enrollment->course->quizzes as $quiz) {
                $hasPass = QuizAttempt::where('quiz_id', $quiz->id)
                    ->where('student_id', $enrollment->student_id)
                    ->where('passed', true)
                    ->exists();

                if (! $hasPass) {
                    return null;
                }
            }
        }

        return $this->issue($enrollment);
    }

    public function issue(Enrollment $enrollment): Certificate
    {
        $existing = Certificate::where('student_id', $enrollment->student_id)
            ->where('course_id', $enrollment->course_id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $certificate = Certificate::create([
            'student_id' => $enrollment->student_id,
            'course_id' => $enrollment->course_id,
            'enrollment_id' => $enrollment->id,
            'certificate_number' => $this->nextNumber(),
            'identifier' => Str::uuid()->toString(),
            'issued_at' => now(),
        ]);

        $student = $enrollment->student;
        $student->notify(new CourseCompleted($enrollment->course));
        $student->notify(new CertificateIssued($certificate));

        return $certificate;
    }

    /**
     * Look up a certificate by its public verification identifier.
     */
    public function verify(string $identifier): ?Certificate
    {
        return Certificate::with(['student', 'course.instructor'])
            ->where('identifier', $identifier)
            ->first();
    }

    public function isEligibleFor(Course $course, int $studentId): bool
    {
        $enrollment = Enrollment::where('student_id', $studentId)
            ->where('course_id', $course->id)
            ->first();

        return $enrollment !== null && $enrollment->status === EnrollmentStatus::Completed;
    }

    private function nextNumber(): string
    {
        $last = (int) Certificate::max('id');

        return 'LMS-'.str_pad((string) (100001 + $last), 6, '0', STR_PAD_LEFT);
    }
}
