<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * Shared `authorize()` for the requests that create or edit course content.
 *
 * The content routes are behind `role:instructor`, which lets an admin through,
 * but these requests used to test `isInstructor()` alone — so an admin was
 * admitted by the middleware and then rejected by the request. Every policy
 * involved (`manage`, `update`, `delete`) already grants an admin, so the
 * request now agrees with them.
 */
trait AuthorizesContentAuthors
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->isAdmin() || $user->isInstructor());
    }
}
