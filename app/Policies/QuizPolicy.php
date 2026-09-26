<?php

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;
use App\Support\CourseAccess;

class QuizPolicy
{
    public function __construct(protected CourseAccess $access) {}

    public function view(User $user, Quiz $quiz): bool
    {
        return $this->access->canLearn($user, $quiz->course);
    }

    public function take(User $user, Quiz $quiz): bool
    {
        return $this->access->canAttemptAssessment($user, $quiz->course);
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
