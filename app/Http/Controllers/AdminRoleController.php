<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class AdminRoleController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::with('permissions:id,name')
            ->withCount('users')
            ->orderByDesc('users_count')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users_count,
            ]);

        $permissions = Permission::orderBy('name')->get(['id', 'name']);

        return response()->json(['roles' => $roles, 'permissions' => $permissions]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name'],
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
        ]);

        $role = Role::create($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        return response()->json([
            'message' => 'Role created.',
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'permissions' => $role->permissions()->pluck('name'),
                'users_count' => 0,
            ],
        ], 201);
    }

    public function update(Request $request, Role $role)
    {
        abort_if(in_array($role->name, ['admin', 'instructor', 'student'], true) && $role->name !== $request->input('name'),
            422, 'Core roles cannot be renamed.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name,'.$role->id],
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
        ]);

        $role->update($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        return response()->json([
            'message' => 'Role updated.',
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'permissions' => $role->permissions()->pluck('name'),
                'users_count' => $role->users()->count(),
            ],
        ]);
    }

    public function destroy(Request $request, Role $role)
    {
        abort_if(in_array($role->name, ['admin', 'instructor', 'student'], true), 422, 'Core roles cannot be deleted.');

        if ($role->users()->exists()) {
            return response()->json(['message' => 'Role is assigned to users and cannot be deleted.'], 409);
        }

        $role->permissions()->detach();
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}
