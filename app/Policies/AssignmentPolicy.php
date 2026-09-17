<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\Enrollment;
use App\Models\User;

class AssignmentPolicy
{
    public function view(User $user, Assignment $assignment): bool
    {
        if ($assignment->course->isOwnedBy($user)) {
            return true;
        }

        return Enrollment::where('student_id', $user->id)
            ->where('course_id', $assignment->course_id)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    public function submit(User $user, Assignment $assignment): bool
    {
        return Enrollment::where('student_id', $user->id)
            ->where('course_id', $assignment->course_id)
            ->where('status', '!=', 'cancelled')
            ->exists();
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
