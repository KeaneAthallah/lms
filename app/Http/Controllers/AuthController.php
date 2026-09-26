<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));
        $user->assignRole(Role::Student);

        Auth::login($user, true);
        $request->session()->regenerate();

        return (new UserResource($user->load('roles')))
            ->additional(['message' => 'Your account has been created.']);
    }

    public function login(LoginRequest $request)
    {
        $credentials = $request->safe()->only(['email', 'password']);

        // A single message covers both failure modes. Telling a caller that the
        // address exists but the account is disabled is user enumeration.
        if (! Auth::attempt($credentials, true)) {
            $this->logFailedLogin($request);

            throw ValidationException::withMessages([
                'email' => [LoginRequest::INVALID_CREDENTIALS],
            ]);
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $this->logFailedLogin($request);

            throw ValidationException::withMessages([
                'email' => [LoginRequest::INVALID_CREDENTIALS],
            ]);
        }

        $request->session()->regenerate();

        return new UserResource($user->load('roles'));
    }

    /**
     * Failed sign-ins are only interesting to the operator, never to the caller.
     */
    private function logFailedLogin(LoginRequest $request): void
    {
        Log::warning('Failed sign-in attempt.', [
            'email' => $request->string('email')->toString(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'You have been signed out.']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return new UserResource($user->load(['roles', 'permissions']));
    }
}
