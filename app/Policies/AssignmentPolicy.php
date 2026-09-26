<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;
use App\Support\CourseAccess;

class AssignmentPolicy
{
    public function __construct(protected CourseAccess $access) {}

    public function view(User $user, Assignment $assignment): bool
    {
        return $this->access->canLearn($user, $assignment->course);
    }

    public function submit(User $user, Assignment $assignment): bool
    {
        return $this->access->canAttemptAssessment($user, $assignment->course);
    }

    public function grade(User $user, Assignment $assignment): bool
    {
        return $assignment->course->isOwnedBy($user);
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $assignment->course->isOwnedBy($user);
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return $assignment->course->isOwnedBy($user);
    }
}
