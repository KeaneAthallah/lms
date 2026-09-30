<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\LoadUserRoles;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS is terminated by a proxy on this host (cloudflared in tunnel.ps1, or a
        // local nginx/PHP-FPM in production), so the original scheme arrives in
        // X-Forwarded-Proto. Without this, asset() builds http:// URLs on an https page
        // and the browser discards the stylesheet as mixed content.
        $middleware->trustProxies(at: '127.0.0.1,::1');

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->web(append: [
            LoadUserRoles::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('app'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
