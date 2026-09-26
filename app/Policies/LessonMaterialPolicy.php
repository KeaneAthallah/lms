<?php

namespace App\Policies;

use App\Models\LessonMaterial;
use App\Models\User;
use App\Support\CourseAccess;

class LessonMaterialPolicy
{
    public function __construct(protected CourseAccess $access) {}

    public function view(User $user, LessonMaterial $material): bool
    {
        return $this->access->canLearn($user, $material->lesson->course);
    }

    public function delete(User $user, LessonMaterial $material): bool
    {
        return $material->lesson->course->isOwnedBy($user);
    }
}
