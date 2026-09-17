<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AppController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('app', [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'headline' => $user->headline,
                'bio' => $user->bio,
                'avatar' => $user->avatar_path ? asset('storage/'.$user->avatar_path) : null,
                'roles' => $user->roles()->pluck('name'),
                'permissions' => $user->permissionNames(),
            ] : null,
        ]);
    }
}
