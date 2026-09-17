<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;

class LessonPolicy
{
    public function view(User $user, Lesson $lesson): bool
    {
        if ($lesson->course->isOwnedBy($user)) {
            return true;
        }

        return Enrollment::where('student_id', $user->id)
            ->where('course_id', $lesson->course_id)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $lesson->course->isOwnedBy($user);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $lesson->course->isOwnedBy($user);
    }
}
