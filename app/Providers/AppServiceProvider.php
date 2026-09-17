<?php

namespace App\Providers;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerLanDevServer();

        // Administrators are authorised for every ability unless the ability
        // explicitly returns false (e.g. deleting your own account).
        Gate::before(function (User $user): ?bool {
            return $user->isAdmin() ? true : null;
        });

        // Students may only enroll in courses that are published.
        Gate::define('enroll', fn (User $user, Course $course): bool => $user->isStudent() && $course->isPublished());
    }

    /**
     * When developing against a device on the LAN, "composer run dev" should
     * serve the app on all interfaces instead of the default 127.0.0.1.
     */
    protected function registerLanDevServer(): void
    {
        if (! app()->runningInConsole() || ! config('app.dev_server_host')) {
            return;
        }

        DevCommands::artisan(
            'serve --host=0.0.0.0 --port='.config('app.dev_server_port'),
            'server',
        );
    }
}
