<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\Traits\GeneratesSeedImages;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    use GeneratesSeedImages;

    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin User', 'password' => 'password', 'is_active' => true],
        );
        $admin->syncRoles([Role::ADMIN]);
        $this->withAvatar($admin);

        $instructors = [
            ['sarah@example.com', 'Sarah Mitchell', 'Frontend Engineer & Laravel enthusiast', 'I have been building web applications for over a decade and love teaching others to build for the web.'],
            ['rachel@example.com', 'Rachel Chen', 'Product Designer', 'Designer focused on accessible, delightful user experiences. Every interface should tell a story.'],
            ['mark@example.com', 'Mark Johnson', 'Data Scientist', 'I translate messy data into clear decisions, and I love demystifying machine learning for everyone.'],
        ];

        foreach ($instructors as [$email, $name, $headline, $bio]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'headline' => $headline, 'bio' => $bio, 'password' => 'password', 'is_active' => true],
            );
            $user->syncRoles([Role::INSTRUCTOR]);
            $this->withAvatar($user);
        }

        $students = [
            ['alex@example.com', 'Alex Rivera'],
            ['jordan@example.com', 'Jordan Lee'],
            ['taylor@example.com', 'Taylor Smith'],
            ['sam@example.com', 'Sam Patel'],
            ['casey@example.com', 'Casey Morgan'],
        ];

        foreach ($students as [$email, $name]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'password', 'is_active' => true],
            );
            $user->syncRoles([Role::STUDENT]);
            $this->withAvatar($user);
        }
    }
}
