<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('roles');

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        if ($request->has('role') && in_array($request->string('role')->toString(), ['student', 'instructor', 'admin'], true)) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->string('role')->toString()));
        }

        if ($request->boolean('inactive')) {
            $query->where('is_active', false);
        }

        $users = $query->latest()->paginate(15)->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request)
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));
        $user->assignRole($request->input('role'));

        $user->update(['is_active' => $request->boolean('is_active', true)]);

        return (new UserResource($user->load('roles')))
            ->additional(['message' => 'User created.']);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->safe()->only(['name', 'email', 'headline', 'bio']);
        $data['is_active'] = (bool) $request->boolean('is_active', $user->is_active);

        if ($request->filled('password')) {
            $data['password'] = $request->input('password');
        }

        if ($request->has('role') && in_array($request->input('role'), ['student', 'instructor', 'admin'], true)) {
            $user->syncRoles([$request->input('role')]);
        }

        $user->update($data);

        return (new UserResource($user->fresh()->load('roles')))
            ->additional(['message' => 'User updated.']);
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
