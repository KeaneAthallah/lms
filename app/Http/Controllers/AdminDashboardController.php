<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function show(Request $request)
    {
        $recentEnrollments = Enrollment::with(['course:id,title,slug', 'student:id,name,avatar_path'])
            ->latest()
            ->take(8)
            ->get()
            ->map(fn (Enrollment $e): array => [
                'id' => $e->id,
                'student' => ['id' => $e->student->id, 'name' => $e->student->name],
                'course' => ['id' => $e->course->id, 'title' => $e->course->title, 'slug' => $e->course->slug],
                'created_at' => $e->created_at?->toISOString(),
            ]);

        $recentUsers = User::latest()->take(8)->get()->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'avatar' => $u->avatar_path ? asset('storage/'.$u->avatar_path) : null,
            'is_active' => (bool) $u->is_active,
            'roles' => $u->roles->pluck('name'),
            'created_at' => $u->created_at?->toISOString(),
        ]);

        $topCourses = Course::withCount(['enrollments', 'lessons'])
            ->orderByDesc('enrollments_count')
            ->take(5)
            ->get()
            ->map(fn (Course $c): array => [
                'id' => $c->id,
                'title' => $c->title,
                'slug' => $c->slug,
                'status' => $c->status->value,
                'enrollments_count' => $c->enrollments_count,
                'lessons_count' => $c->lessons_count,
            ]);

        return response()->json([
            'stats' => [
                'total_users' => User::count(),
                'students' => User::whereHas('roles', fn ($q) => $q->where('name', 'student'))->count(),
                'instructors' => User::whereHas('roles', fn ($q) => $q->where('name', 'instructor'))->count(),
                'total_courses' => Course::count(),
                'published_courses' => Course::published()->count(),
                'draft_courses' => Course::where('status', 'draft')->count(),
                'total_enrollments' => Enrollment::count(),
                'completed_enrollments' => Enrollment::where('status', 'completed')->count(),
                'total_certificates' => Certificate::count(),
            ],
            'recent_enrollments' => $recentEnrollments,
            'recent_users' => $recentUsers,
            'top_courses' => $topCourses,
        ]);
    }
}
