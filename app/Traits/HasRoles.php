<?php

namespace App\Traits;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public function grantRole(\App\Role|string|Role $role, ?string $label = null): void
    {
        if ($role instanceof \App\Role) {
            $role = $role->value;
        }

        if (! $role instanceof Role) {
            $role = Role::firstOrCreate(['name' => $role], ['label' => $label ?? $role]);
        }

        $this->roles()->syncWithoutDetaching($role->id);
    }

    public function assignRole(\App\Role|string|Role $role, ?string $label = null): void
    {
        $this->grantRole($role, $label);
    }

    public function syncRoles(array $roles, ?string $roleName = null): void
    {
        $ids = array_map(fn (\App\Role|string|Role $role): int => match (true) {
            $role instanceof \App\Role => Role::firstOrCreate(['name' => $role->value], ['label' => $roleName ?? $role->value])->id,
            $role instanceof Role => $role->getKey(),
            default => Role::firstOrCreate(['name' => $role], ['label' => $roleName ?? $role])->id,
        }, $roles);

        $this->roles()->sync($ids);
    }

    public function hasRole(\App\Role|string|Role $role): bool
    {
        $name = $role instanceof \App\Role
            ? $role->value
            : ($role instanceof Role ? $role->name : $role);

        return $this->roles()->where('name', $name)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        $names = array_map(fn ($role): string => $role instanceof \App\Role
            ? $role->value
            : ($role instanceof Role ? $role->name : $role), $roles);

        return $this->roles()->whereIn('name', $names)->exists();
    }

    public function givePermissionTo(string|Permission $permission, ?string $label = null): void
    {
        if (! $permission instanceof Permission) {
            $permission = Permission::firstOrCreate(['name' => $permission], ['label' => $label ?? $permission]);
        }

        $this->permissions()->syncWithoutDetaching($permission->id);
    }

    public function grantPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->givePermissionTo($permission);
        }
    }

    /**
     * Permissions granted directly to the user, not via a role.
     */
    public function directPermissions(): BelongsToMany
    {
        return $this->permissions();
    }

    /**
     * All permissions the user holds, including those inherited from roles.
     *
     * @return array<string>
     */
    public function permissionNames(): array
    {
        $rolePermissions = $this->roles()
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions)
            ->pluck('name');

        return $rolePermissions
            ->merge($this->permissions()->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string|Permission $permission): bool
    {
        $name = $permission instanceof Permission ? $permission->name : $permission;

        $roleNames = $this->roles()->pluck('roles.name');

        $viaRoles = Role::whereIn('name', $roleNames)
            ->whereHas('permissions', fn ($query) => $query->where('name', $name))
            ->exists();

        return $viaRoles || $this->permissions()->where('name', $name)->exists();
    }

    public function hasAnyPermission(array $permissions): bool
    {
        $names = array_map(fn (string|Permission $permission): string => $permission instanceof Permission
            ? $permission->name
            : $permission, $permissions);

        foreach ($names as $name) {
            if ($this->hasPermission($name)) {
                return true;
            }
        }

        return false;
    }
}
