<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Notifications\CertificateIssued;
use App\Notifications\CourseCompleted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
        // One certificate per student per course is a database invariant (unique
        // index on (student_id, course_id)). The enrollment row is locked so a
        // completion processed twice — a request and a queued job, say — issues
        // one certificate and returns it to the loser of the race.
        return DB::transaction(function () use ($enrollment): Certificate {
            Enrollment::whereKey($enrollment->getKey())->lockForUpdate()->first();

            if ($existing = $this->existingCertificate($enrollment)) {
                return $existing;
            }

            // `nextNumber()` reads `max(id)`, which is itself racy. The unique
            // index on certificate_number is the real guarantee; a collision
            // just means the loser recomputes from the now-larger max.
            for ($try = 0; $try < 5; $try++) {
                try {
                    $certificate = Certificate::create([
                        'student_id' => $enrollment->student_id,
                        'course_id' => $enrollment->course_id,
                        'enrollment_id' => $enrollment->id,
                        'certificate_number' => $this->nextNumber(),
                        'identifier' => Str::uuid()->toString(),
                        'issued_at' => now(),
                    ]);
                } catch (QueryException $exception) {
                    if ($existing = $this->existingCertificate($enrollment)) {
                        return $existing;
                    }

                    if ($try === 4) {
                        throw $exception;
                    }

                    continue;
                }

                $student = $enrollment->student;

                // Queued, so both of these can be picked up by a worker before
                // this transaction commits -- and both read the certificate row
                // this transaction is still writing. `afterCommit()` holds them
                // until the commit, or discards them if it rolls back, so the
                // mail can never describe a certificate nobody can see yet.
                $student->notify((new CourseCompleted($enrollment->course))->afterCommit());
                $student->notify((new CertificateIssued($certificate))->afterCommit());

                return $certificate;
            }

            // Unreachable: the loop either returns or rethrows.
            throw new RuntimeException('Unable to issue a unique certificate number.');
        });
    }

    private function existingCertificate(Enrollment $enrollment): ?Certificate
    {
        return Certificate::where('student_id', $enrollment->student_id)
            ->where('course_id', $enrollment->course_id)
            ->first();
    }

    /**
     * Resolve a public reference to a certificate, by verification identifier or by
     * certificate number.
     *
     * Both keys are unique and both are printed on the certificate, so accepting
     * either is what lets a holder with only the number on a paper copy be told
     * whether it is genuine. The identifier is the canonical reference -- it is
     * what the document URL and every notification link carry -- so callers get
     * back the certificate rather than the key that matched it.
     */
    public function verify(string $reference): ?Certificate
    {
        return Certificate::with(['student', 'course.instructor'])
            ->where('identifier', $reference)
            ->orWhere('certificate_number', $reference)
            ->first();
    }

    private function nextNumber(): string
    {
        $last = (int) Certificate::max('id');

        return 'LMS-'.str_pad((string) (100001 + $last), 6, '0', STR_PAD_LEFT);
    }
}
