<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request. Accepts one or more role names and grants
     * access when the authenticated user holds any of them.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || (! $user->isAdmin() && ! $user->hasAnyRole($roles))) {
            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
