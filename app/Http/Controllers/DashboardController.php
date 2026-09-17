<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Enrollment;
use App\Models\Grade;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function student(Request $request)
    {
        $user = $request->user();

        $enrollments = Enrollment::where('student_id', $user->id)
            ->with(['course' => fn ($q) => $q->withCount(['lessons', 'enrollments'])->with(['instructor:id,name,headline', 'category:id,name,slug'])])
            ->orderByDesc('last_accessed_at')
            ->get();

        $active = $enrollments->where('status', 'active')->values();
        $completed = $enrollments->where('status', 'completed')->values();

        $overallProgress = $enrollments->isEmpty()
            ? 0
            : (int) round($enrollments->avg('progress_percent'));

        $enrolledCourseIds = $enrollments->pluck('course_id');

        $upcomingAssignments = Assignment::active()
            ->whereIn('course_id', $enrolledCourseIds)
            ->where(function ($q) {
                $q->whereNull('due_at')->orWhere('due_at', '>=', now());
            })
            ->with(['course:id,title,slug'])
            ->withCount('submissions')
            ->orderBy('due_at')
            ->take(5)
            ->get()
            ->map(fn (Assignment $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'due_at' => $a->due_at?->toISOString(),
                'max_score' => (float) $a->max_score,
                'status' => $a->status->value,
                'course' => [
                    'id' => $a->course->id,
                    'title' => $a->course->title,
                    'slug' => $a->course->slug,
                ],
                'has_submission' => $a->submissions()->where('student_id', $user->id)->exists(),
            ]);

        $recentGrades = Grade::where('student_id', $user->id)
            ->with('course:id,title,slug')
            ->orderByDesc('graded_at')
            ->take(5)
            ->get()
            ->map(fn (Grade $g): array => [
                'id' => $g->id,
                'type' => $g->type,
                'score' => (float) $g->score,
                'max_score' => (float) $g->max_score,
                'percentage' => (float) $g->percentage,
                'feedback' => $g->feedback,
                'graded_at' => $g->graded_at?->toISOString(),
                'course' => $g->course ? [
                    'id' => $g->course->id,
                    'title' => $g->course->title,
                    'slug' => $g->course->slug,
                ] : null,
            ]);

        $recentNotifications = $user->notifications()->take(8)->get()->map(fn ($n): array => [
            'id' => (string) $n->id,
            'title' => $n->data['title'] ?? 'Notification',
            'message' => $n->data['message'] ?? '',
            'type' => $n->data['type'] ?? $n->type,
            'created_at' => $n->created_at?->toISOString(),
            'read' => $n->read_at !== null,
        ]);

        $certificates = $user->certificates()->with('course:id,title,slug')->orderByDesc('issued_at')->take(3)->get()
            ->map(fn ($c): array => [
                'id' => $c->id,
                'certificate_number' => $c->certificate_number,
                'identifier' => $c->identifier,
                'issued_at' => $c->issued_at?->toISOString(),
                'course' => ['id' => $c->course->id, 'title' => $c->course->title, 'slug' => $c->course->slug],
            ]);

        return response()->json([
            'stats' => [
                'enrolled_courses' => $enrollments->count(),
                'in_progress' => $active->count(),
                'completed' => $completed->count(),
                'overall_progress' => $overallProgress,
                'certificates' => $user->certificates()->count(),
            ],
            'continue_learning' => $active->take(3)->map(fn (Enrollment $e): array => [
                'course' => $this->courseCard($e->course),
                'progress_percent' => $e->progress_percent,
                'last_accessed_at' => $e->last_accessed_at?->toISOString(),
            ]),
            'courses_in_progress' => $active->map(fn (Enrollment $e): array => [
                'course' => $this->courseCard($e->course),
                'progress_percent' => $e->progress_percent,
            ]),
            'completed_courses' => $completed->map(fn (Enrollment $e): array => [
                'course' => $this->courseCard($e->course),
                'completed_at' => $e->completed_at?->toISOString(),
                'has_certificate' => $user->certificates()->where('course_id', $e->course_id)->exists(),
            ]),
            'upcoming_assignments' => $upcomingAssignments,
            'recent_grades' => $recentGrades,
            'certificates' => $certificates,
            'recent_activity' => $recentNotifications,
        ]);
    }

    private function courseCard($course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'thumbnail_url' => $course->thumbnail_path ? asset('storage/'.$course->thumbnail_path) : null,
            'level' => $course->level,
            'duration_minutes' => $course->duration_minutes,
            'lessons_count' => $course->lessons_count ?? null,
            'instructor' => ['id' => $course->instructor->id ?? null, 'name' => $course->instructor->name ?? null],
            'category' => $course->category ? ['id' => $course->category->id, 'name' => $course->category->name, 'slug' => $course->category->slug] : null,
        ];
    }
}
