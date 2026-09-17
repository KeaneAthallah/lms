<?php

namespace Database\Seeders;

use App\Models\CourseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CourseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['Web Development', 'Build modern web applications with Laravel, React, and friends.', '💻'],
            ['UI & UX Design', 'Design clean, accessible, and delightful digital products.', '🎨'],
            ['Data Science', 'Analyze data and build machine learning models with Python.', '📊'],
            ['Business', 'Develop leadership, strategy, and operations skills.', '💼'],
            ['Marketing', 'Reach the right audience with modern marketing.', '📣'],
        ];

        foreach ($categories as [$name, $description, $icon]) {
            CourseCategory::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'description' => $description, 'icon' => $icon, 'status' => 'active'],
            );
        }
    }
}
