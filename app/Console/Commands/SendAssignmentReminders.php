<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use App\Notifications\AssignmentDeadlineReminder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SendAssignmentReminders extends Command
{
    protected $signature = 'lms:send-assignment-reminders';

    protected $description = 'Notify students about assignments that are due soon.';

    /**
     * Recipients are read in slices so the command's memory is bounded by the
     * slice rather than by the size of the student body.
     */
    private const ENROLLMENT_CHUNK = 200;

    public function handle(): int
    {
        $window = (int) config('lms.assignment_reminder_hours');

        $dueSoon = Assignment::active()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addHours($window)])
            ->get(['id', 'course_id', 'title', 'due_at']);

        if ($dueSoon->isEmpty()) {
            $this->info('Sent 0 assignment reminders.');

            return self::SUCCESS;
        }

        $dueByCourse = $dueSoon->groupBy('course_id');

        // Which assignment/student pairs are already done, answered in one
        // query. This used to call `submissionFor()` once per pair, so the
        // command issued one query per assignment per enrolled student: the
        // product of the two counts, which is an N+1 with a multiplier on it.
        // The eager load did the same damage to memory by pulling every
        // enrollment and every student for every due course into memory at once.
        $alreadySubmitted = AssignmentSubmission::query()
            ->whereIn('assignment_id', $dueSoon->pluck('id'))
            ->get(['assignment_id', 'student_id'])
            ->mapWithKeys(fn (AssignmentSubmission $row): array => [$row->assignment_id.':'.$row->student_id => true]);

        $sent = 0;

        Enrollment::query()
            ->whereIn('course_id', $dueByCourse->keys())
            ->select(['id', 'course_id', 'student_id'])
            ->with('student:id,id,name,email')
            ->chunkById(self::ENROLLMENT_CHUNK, function (Collection $enrollments) use ($dueByCourse, $alreadySubmitted, &$sent): void {
                foreach ($enrollments as $enrollment) {
                    $student = $enrollment->student;

                    if (! $student) {
                        continue;
                    }

                    foreach ($dueByCourse->get($enrollment->course_id, collect()) as $assignment) {
                        if (isset($alreadySubmitted[$assignment->id.':'.$enrollment->student_id])) {
                            continue;
                        }

                        // Queued and after-commit, so the mail is sent by a worker
                        // rather than by this command. A run that would previously
                        // have stalled for minutes on an unreachable mail server
                        // now finishes as fast as the dispatches can be written.
                        $student->notify((new AssignmentDeadlineReminder($assignment))->afterCommit());
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} assignment reminders.");

        return self::SUCCESS;
    }
}
