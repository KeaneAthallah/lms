<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'users.manage' => 'Manage users',
            'roles.manage' => 'Manage roles',
            'permissions.manage' => 'Manage permissions',
            'courses.manage' => 'Manage all courses',
            'categories.manage' => 'Manage categories',
            'enrollments.manage' => 'Manage enrollments',
            'certificates.manage' => 'Manage certificates',
            'reports.view' => 'View reports',
            'settings.manage' => 'Manage settings',
        ];

        foreach ($permissions as $name => $label) {
            Permission::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        $admin = Role::updateOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $instructor = Role::updateOrCreate(['name' => 'instructor'], ['label' => 'Instructor']);
        $student = Role::updateOrCreate(['name' => 'student'], ['label' => 'Student']);

        $admin->permissions()->sync(Permission::pluck('id'));
        $instructor->permissions()->sync([]);
        $student->permissions()->sync([]);
    }
}
