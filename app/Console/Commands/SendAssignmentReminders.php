<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Notifications\AssignmentDeadlineReminder;
use Illuminate\Console\Command;

class SendAssignmentReminders extends Command
{
    protected $signature = 'lms:send-assignment-reminders';

    protected $description = 'Notify students about assignments that are due soon.';

    public function handle(): int
    {
        $dueSoon = Assignment::active()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addHours((int) config('lms.assignment_reminder_hours'))])
            ->with('course.enrollments.student')
            ->get();

        $sent = 0;

        foreach ($dueSoon as $assignment) {
            foreach ($assignment->course->enrollments as $enrollment) {
                $student = $enrollment->student;

                if ($assignment->submissionFor($student)) {
                    continue;
                }

                $student->notify(new AssignmentDeadlineReminder($assignment));
                $sent++;
            }
        }

        $this->info("Sent {$sent} assignment reminders.");

        return self::SUCCESS;
    }
}
