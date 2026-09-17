<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\User;

class QuizPolicy
{
    public function view(User $user, Quiz $quiz): bool
    {
        if ($quiz->course->isOwnedBy($user)) {
            return true;
        }

        return Enrollment::where('student_id', $user->id)
            ->where('course_id', $quiz->course_id)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    public function take(User $user, Quiz $quiz): bool
    {
        return Enrollment::where('student_id', $user->id)
            ->where('course_id', $quiz->course_id)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    public function update(User $user, Quiz $quiz): bool
    {
        return $quiz->course->isOwnedBy($user);
    }

    public function delete(User $user, Quiz $quiz): bool
    {
        return $quiz->course->isOwnedBy($user);
    }
}
