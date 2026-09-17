<?php

namespace Database\Seeders;

use App\Models\Certificate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CertificateSeeder extends Seeder
{
    public function run(): void
    {
        $students = ['jordan@example.com', 'sam@example.com'];

        foreach ($students as $email) {
            $student = User::where('email', $email)->firstOrFail();

            foreach ($student->enrollments()->where('status', 'completed')->with('course')->get() as $enrollment) {
                $existing = Certificate::where('student_id', $student->id)
                    ->where('course_id', $enrollment->course_id)
                    ->exists();

                if ($existing) {
                    continue;
                }

                Certificate::create([
                    'student_id' => $student->id,
                    'course_id' => $enrollment->course_id,
                    'enrollment_id' => $enrollment->id,
                    'certificate_number' => 'LMS-'.str_pad((string) (1000 + $student->id + $enrollment->course_id), 6, '0', STR_PAD_LEFT),
                    'identifier' => Str::uuid()->toString(),
                    'issued_at' => Carbon::parse($enrollment->completed_at),
                ]);
            }
        }
    }
}
