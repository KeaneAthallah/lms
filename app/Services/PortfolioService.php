<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\LessonProgress;
use App\Models\QuizAttempt;
use App\Models\User;

/**
 * Aggregates a student's verifiable learning evidence into a portfolio:
 * certificates, grades, mastered concepts, and real learning time. Nothing is
 * estimated beyond what the LMS has actually recorded.
 */
class PortfolioService
{
    /**
     * @return array<string, mixed>
     */
    public function portfolioFor(User $student): array
    {
        $mastery = (new MasteryCalculator)->masteryFor($student);

        $skills = [];
        $mastered = 0;
        $progressing = 0;

        foreach ($mastery['courses'] as $courseEntry) {
            foreach ($courseEntry['concepts'] as $concept) {
                if ($concept['status'] === MasteryCalculator::STATUS_MASTERED) {
                    $mastered++;
                } else {
                    $progressing++;
                }

                $skills[] = [
                    'concept' => $concept['concept'],
                    'course' => $concept['course'],
                    'status' => $concept['status'],
                    'mastery_percent' => $concept['mastery_percent'],
                ];
            }
        }

        $certificates = Certificate::query()
            ->where('student_id', $student->id)
            ->orderByDesc('issued_at')
            ->with(['course:id,title,slug,instructor_id', 'course.instructor:id,name'])
            ->get();

        $recentGrades = Grade::query()
            ->where('student_id', $student->id)
            ->orderByDesc('graded_at')
            ->limit(10)
            ->with('course:id,title,slug')
            ->get();

        $enrollments = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('status', '!=', EnrollmentStatus::Cancelled->value)
            ->orderByDesc('enrolled_at')
            ->with('course:id,title,slug')
            ->get();

        return [
            'method' => 'deterministic_rules',
            'summary' => [
                'certificates_count' => $certificates->count(),
                'courses_completed' => $certificates->pluck('course_id')->unique()->count(),
                'courses_in_progress' => $enrollments
                    ->filter(fn (Enrollment $enrollment) => ! $enrollment->isCompleted() && $enrollment->progress_percent < 100)
                    ->count(),
                'concepts_mastered' => $mastered,
                'concepts_in_progress' => $progressing,
                'overall_mastery_percent' => $mastery['overall_percent'],
                'learning_minutes_total' => $this->totalLearningMinutes($student),
            ],
            'certificates' => $certificates->map(fn (Certificate $certificate) => [
                'identifier' => $certificate->identifier,
                'issued_at' => $certificate->issued_at?->toIso8601String(),
                'course' => [
                    'id' => $certificate->course?->id,
                    'title' => $certificate->course?->title,
                    'slug' => $certificate->course?->slug,
                ],
                'instructor' => $certificate->course?->instructor?->name,
            ])->values()->all(),
            'recent_grades' => $recentGrades->map(fn (Grade $grade) => [
                'id' => $grade->id,
                'type' => $grade->type,
                'score' => (float) $grade->score,
                'max_score' => (float) $grade->max_score,
                'percentage' => $grade->percentage !== null ? (float) $grade->percentage : null,
                'graded_at' => $grade->graded_at?->toIso8601String(),
                'course' => $grade->course ? ['id' => $grade->course->id, 'title' => $grade->course->title, 'slug' => $grade->course->slug] : null,
            ])->values()->all(),
            'skills' => array_values($skills),
            'courses' => $enrollments->map(fn (Enrollment $enrollment) => [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'progress_percent' => (int) $enrollment->progress_percent,
                'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
                'course' => $enrollment->course ? ['id' => $enrollment->course->id, 'title' => $enrollment->course->title, 'slug' => $enrollment->course->slug] : null,
            ])->values()->all(),
        ];
    }

    protected function totalLearningMinutes(User $student): int
    {
        $completed = LessonProgress::query()
            ->where('student_id', $student->id)
            ->whereNotNull('completed_at')
            ->with('lesson:id,duration_seconds')
            ->get(['lesson_id', 'completed_at']);

        $lessonMinutes = $completed->sum(
            fn (LessonProgress $row) => is_numeric($row->lesson?->duration_seconds) && (int) $row->lesson->duration_seconds > 0
                ? (int) ceil((int) $row->lesson->duration_seconds / 60)
                : 0
        );

        $attempts = QuizAttempt::query()
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->whereNotNull('started_at')
            ->get(['started_at', 'submitted_at']);

        $attemptMinutes = $attempts->sum(function (QuizAttempt $attempt): int {
            $seconds = max(0, (int) $attempt->started_at->diffInSeconds($attempt->submitted_at));

            return min(120, (int) ceil($seconds / 60));
        });

        return $lessonMinutes + $attemptMinutes;
    }
}
