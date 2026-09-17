<?php

namespace App\Policies;

use App\Models\AssignmentSubmission;
use App\Models\User;

class AssignmentSubmissionPolicy
{
    public function view(User $user, AssignmentSubmission $submission): bool
    {
        if ((int) $submission->student_id === (int) $user->id) {
            return true;
        }

        return $submission->assignment->course->isOwnedBy($user);
    }

    public function download(User $user, AssignmentSubmission $submission): bool
    {
        return $this->view($user, $submission);
    }
}
