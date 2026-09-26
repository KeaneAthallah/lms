<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;
use App\Support\CourseAccess;

class LessonPolicy
{
    public function __construct(protected CourseAccess $access) {}

    public function view(User $user, Lesson $lesson): bool
    {
        return $this->access->canLearn($user, $lesson->course);
    }

    /**
     * May the user read this lesson's body and media?
     */
    public function viewContent(User $user, Lesson $lesson): bool
    {
        return $this->access->canViewLessonContent($user, $lesson);
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
