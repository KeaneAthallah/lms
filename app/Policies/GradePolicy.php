<?php

namespace App\Policies;

use App\Models\Grade;
use App\Models\User;

class GradePolicy
{
    public function view(User $user, Grade $grade): bool
    {
        if ((int) $grade->student_id === (int) $user->id) {
            return true;
        }

        return $grade->course->isOwnedBy($user);
    }
}
