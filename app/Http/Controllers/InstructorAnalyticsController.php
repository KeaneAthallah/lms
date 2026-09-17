<?php

namespace App\Http\Controllers;

use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;

class InstructorAnalyticsController extends Controller
{
    public function show(Request $request, Course $course)
    {
        $this->authorize('manage', $course);

        $enrollments = Enrollment::where('course_id', $course->id)->where('status', 'active')->get(['id', 'status', 'progress_percent']);

        $totalEnrollments = Enrollment::where('course_id', $course->id)->count();
        $completed = Enrollment::where('course_id', $course->id)->where('status', 'completed')->count();

        $buckets = ['0-24' => 0, '25-49' => 0, '50-74' => 0, '75-99' => 0];
        foreach ($enrollments as $enrollment) {
            $p = (int) $enrollment->progress_percent;
            if ($p < 25) {
                $buckets['0-24']++;
            } elseif ($p < 50) {
                $buckets['25-49']++;
            } elseif ($p < 75) {
                $buckets['50-74']++;
            } else {
                $buckets['75-99']++;
            }
        }
        $buckets['100'] = $completed;

        $assignmentIds = $course->assignments()->pluck('id');
        $submissions = AssignmentSubmission::whereIn('assignment_id', $assignmentIds);
        $gradedSubmissions = (clone $submissions)->where('status', 'graded');

        $quizIds = $course->quizzes()->pluck('id');
        $quizAttempts = QuizAttempt::whereIn('quiz_id', $quizIds)->whereIn('status', ['completed', 'passed', 'failed']);
        $quizPassed = QuizAttempt::whereIn('quiz_id', $quizIds)->where('passed', true)->count();

        $totalQuizzes = $course->quizzes()->count();

        return response()->json([
            'enrollment' => [
                'total' => $totalEnrollments,
                'completed' => $completed,
                'completion_rate' => $totalEnrollments > 0 ? round(($completed / $totalEnrollments) * 100, 1) : 0,
                'average_progress' => $enrollments->count() ? (float) round($enrollments->avg('progress_percent'), 1) : 0,
                'distribution' => $buckets,
            ],
            'assignments' => [
                'total' => $assignmentIds->count(),
                'submitted' => $submissions->count(),
                'graded' => $gradedSubmissions->count(),
                'pending' => $submissions->where('status', 'submitted')->count(),
            ],
            'quizzes' => [
                'total_quizzes' => $totalQuizzes,
                'attempts' => $quizAttempts->count(),
                'passed' => $quizPassed,
                'pass_rate' => $quizAttempts->count() > 0 ? round(($quizPassed / $quizAttempts->count()) * 100, 1) : 0,
                'average_score' => $quizAttempts->count() > 0
                    ? (float) round($quizAttempts->whereNotNull('score_percentage')->avg('score_percentage'), 1)
                    : 0,
            ],
        ]);
    }
}
