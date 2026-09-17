<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        if (! Auth::attempt($credentials, true)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Your account has been disabled. Please contact support.'],
            ]);
        }

        $request->session()->regenerate();

        return new UserResource($user->load('roles'));
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
