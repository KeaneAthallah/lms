<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Support\CourseAccess;

class CoursePolicy
{
    public function __construct(protected CourseAccess $access) {}

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
        return $this->access->canLearn($user, $course);
    }

    public function manageStudents(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isOwnedBy($user);
    }
}
