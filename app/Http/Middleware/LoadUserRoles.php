<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads the authenticated user's roles once per request.
 *
 * `isAdmin()` runs in `Gate::before`, in every `role:` middleware hit and in
 * most policies, and each call used to be its own query. Eager loading the
 * relation once turns a page that authorises a hundred times from a hundred
 * queries into one.
 */
class LoadUserRoles
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $user->loadMissing('roles.permissions');
        }

        return $next($request);
    }
}
