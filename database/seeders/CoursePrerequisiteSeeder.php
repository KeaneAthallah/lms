<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CoursePrerequisite;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Links published courses within each category into an informational
 * prerequisite chain (first created first). Purely advisory - prerequisites
 * never block enrollment.
 */
class CoursePrerequisiteSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $byCategory = Course::query()
            ->whereNotNull('category_id')
            ->published()
            ->orderBy('created_at')
            ->get()
            ->groupBy('category_id');

        foreach ($byCategory as $courses) {
            $courses = $courses->values();

            foreach ($courses as $index => $course) {
                $next = $courses->get($index + 1);

                if ($next === null) {
                    continue;
                }

                CoursePrerequisite::firstOrCreate(
                    ['course_id' => $next->id, 'prerequisite_course_id' => $course->id],
                    ['sort_order' => $index + 1]
                );
            }
        }
    }
}
