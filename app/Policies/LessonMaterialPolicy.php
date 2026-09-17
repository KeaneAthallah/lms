<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\LessonMaterial;
use App\Models\User;

class LessonMaterialPolicy
{
    public function view(User $user, LessonMaterial $material): bool
    {
        $course = $material->lesson->course;

        if ($course->isOwnedBy($user)) {
            return true;
        }

        return Enrollment::where('student_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    public function delete(User $user, LessonMaterial $material): bool
    {
        return $material->lesson->course->isOwnedBy($user);
    }
}
