<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;

class InstructorDashboardController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        $courseIds = $user->courses()->pluck('courses.id');

        $enrollments = Enrollment::whereIn('course_id', $courseIds)
            ->with(['course:id,title,slug', 'student:id,name,avatar_path'])
            ->latest()
            ->take(5)
            ->get();

        $assignmentIds = Assignment::whereIn('course_id', $courseIds)->pluck('id');
        $pendingSubmissions = AssignmentSubmission::whereIn('assignment_id', $assignmentIds)
            ->where('status', 'submitted')
            ->with(['assignment:id,title,course_id', 'student:id,name,avatar_path', 'assignment.course:id,title,slug'])
            ->orderByDesc('submitted_at')
            ->take(6)
            ->get();

        $courseStats = $user->courses()
            ->with(['enrollments:id,course_id,status,progress_percent'])
            ->withCount('enrollments')
            ->orderByDesc('enrollments_count')
            ->get()
            ->map(fn (Course $course): array => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'status' => $course->status->value,
                'enrollments_count' => $course->enrollments_count,
                'completed_count' => $course->enrollments->where('status', 'completed')->count(),
                'average_progress' => $course->enrollments->count()
                    ? (int) round($course->enrollments->avg('progress_percent'))
                    : 0,
            ]);

        $quizIds = Quiz::whereIn('course_id', $courseIds)->pluck('id');
        $recentQuizActivity = QuizAttempt::whereIn('quiz_id', $quizIds)
            ->whereIn('status', ['completed', 'passed', 'failed'])
            ->with(['student:id,name,avatar_path', 'quiz:id,title'])
            ->orderByDesc('submitted_at')
            ->take(6)
            ->get()
            ->map(fn (QuizAttempt $attempt): array => [
                'id' => $attempt->id,
                'student' => $attempt->student->name,
                'avatar' => $attempt->student->avatar_path ? asset('storage/'.$attempt->student->avatar_path) : null,
                'quiz' => $attempt->quiz->title,
                'score_percentage' => (float) ($attempt->score_percentage ?? 0),
                'passed' => (bool) $attempt->passed,
                'submitted_at' => $attempt->submitted_at?->toISOString(),
            ]);

        return response()->json([
            'stats' => [
                'total_courses' => $courseIds->count(),
                'published_courses' => $user->courses()->published()->count(),
                'draft_courses' => $user->courses()->where('status', 'draft')->count(),
                'total_students' => Enrollment::whereIn('course_id', $courseIds)->distinct()->count('student_id'),
                'total_enrollments' => Enrollment::whereIn('course_id', $courseIds)->count(),
                'completed_enrollments' => Enrollment::whereIn('course_id', $courseIds)->where('status', 'completed')->count(),
                'pending_assignments' => $pendingSubmissions->count(),
            ],
            'recent_enrollments' => $enrollments->map(fn (Enrollment $e): array => [
                'course' => ['id' => $e->course->id, 'title' => $e->course->title, 'slug' => $e->course->slug],
                'student' => ['id' => $e->student->id, 'name' => $e->student->name],
                'progress_percent' => $e->progress_percent,
                'created_at' => $e->created_at?->toISOString(),
            ]),
            'pending_submissions' => $pendingSubmissions->map(fn (AssignmentSubmission $s): array => [
                'id' => $s->id,
                'student' => [
                    'id' => $s->student->id,
                    'name' => $s->student->name,
                    'avatar' => $s->student->avatar_path ? asset('storage/'.$s->student->avatar_path) : null,
                ],
                'assignment' => ['id' => $s->assignment->id, 'title' => $s->assignment->title],
                'course' => ['id' => $s->assignment->course->id, 'title' => $s->assignment->course->title, 'slug' => $s->assignment->course->slug],
                'submitted_at' => $s->submitted_at?->toISOString(),
            ]),
            'course_stats' => $courseStats,
            'recent_quiz_activity' => $recentQuizActivity,
        ]);
    }
}
