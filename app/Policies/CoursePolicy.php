<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

class CoursePolicy
{
    public function view(?User $user, Course $course): bool
    {
        if ($course->isPublished()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || $course->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return $user->isInstructor();
    }

    public function update(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isOwnedBy($user);
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isOwnedBy($user);
    }

    public function publish(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isOwnedBy($user);
    }

    public function manage(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isOwnedBy($user);
    }

    public function archive(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isOwnedBy($user);
    }

    public function learn(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isOwnedBy($user)
            || Enrollment::where('student_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', '!=', 'cancelled')
                ->exists();
    }

    public function manageStudents(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isOwnedBy($user);
    }
}
