<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function view(User $user, User $target): bool
    {
        return (int) $user->id === (int) $target->id || $user->hasPermission('users.manage');
    }

    public function update(User $user, User $target): bool
    {
        return (int) $user->id === (int) $target->id || $user->hasPermission('users.manage');
    }

    public function delete(User $user, User $target): bool
    {
        if ((int) $user->id === (int) $target->id) {
            return false;
        }

        return $user->hasPermission('users.manage');
    }
}
