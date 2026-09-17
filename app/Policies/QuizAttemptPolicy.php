<?php

namespace App\Policies;

use App\Models\QuizAttempt;
use App\Models\User;

class QuizAttemptPolicy
{
    public function view(User $user, QuizAttempt $attempt): bool
    {
        if ((int) $attempt->student_id === (int) $user->id) {
            return true;
        }

        return $attempt->quiz->course->isOwnedBy($user);
    }

    public function submit(User $user, QuizAttempt $attempt): bool
    {
        return (int) $attempt->student_id === (int) $user->id;
    }
}
