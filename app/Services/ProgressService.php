<?php

namespace App\Services;

use App\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;

class ProgressService
{
    public function __construct(protected CertificateService $certificates) {}

    /**
     * Ensure a progress record exists for the student + lesson and touch it.
     */
    public function markStarted(Lesson $lesson, User $student): LessonProgress
    {
        $progress = LessonProgress::firstOrCreate(
            ['student_id' => $student->id, 'lesson_id' => $lesson->id],
            [
                'course_id' => $lesson->course_id,
                'progress_percent' => 0,
                'started_at' => now(),
            ],
        );

        $progress->last_accessed_at = now();
        $progress->save();

        $enrollment = $this->enrollmentOrNull($lesson->course, $student);
        $enrollment?->update(['last_accessed_at' => now()]);

        return $progress;
    }

    /**
     * Record video playback position and compute lesson progress. Lessons are
     * only marked complete once the configured watch threshold is reached.
     */
    public function recordVideoProgress(Lesson $lesson, User $student, int $positionSeconds): LessonProgress
    {
        $progress = $this->markStarted($lesson, $student);
        $progress->video_position_seconds = max(0, $positionSeconds);

        if ($lesson->duration_seconds) {
            $percent = (int) round(($positionSeconds / $lesson->duration_seconds) * 100);
            $progress->progress_percent = min(100, max(0, $percent));

            if ($percent >= config('lms.video_completion_threshold_percent')) {
                $this->completeLessonRecord($progress);
            }
        }

        $progress->last_accessed_at = now();
        $progress->save();

        return $progress;
    }

    /**
     * Manually complete a lesson (text, document, external link, quizzes, ...).
     */
    public function completeLesson(Lesson $lesson, User $student): LessonProgress
    {
        $progress = $this->markStarted($lesson, $student);
        $this->completeLessonRecord($progress);
        $progress->save();

        $this->recalculateCourseProgress($lesson->course, $student);

        return $progress;
    }

    /**
     * Recalculate an enrollment's overall progress from completed lessons and
     * flip it to completed when 100% is reached.
     */
    public function recalculateCourseProgress(Course $course, User $student): int
    {
        $total = $course->lessons()->published()->count();
        $completed = LessonProgress::query()
            ->where('student_id', $student->id)
            ->where('course_id', $course->id)
            ->whereNotNull('completed_at')
            ->count();

        $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
        $percent = min(100, max(0, $percent));

        $enrollment = $this->enrollmentOrNull($course, $student);
        if ($enrollment) {
            $enrollment->progress_percent = $percent;
            $enrollment->last_accessed_at = now();

            if ($percent >= 100 && $enrollment->status !== EnrollmentStatus::Completed) {
                $enrollment->status = EnrollmentStatus::Completed;
                $enrollment->completed_at = $enrollment->completed_at ?? now();
            }

            $enrollment->save();

            if ($enrollment->isCompleted()) {
                $this->certificates->issueIfEligible($enrollment);
            }
        }

        return $percent;
    }

    public function courseProgressPercent(Course $course, User $student): int
    {
        $enrollment = $this->enrollmentOrNull($course, $student);

        return $enrollment?->progress_percent ?? 0;
    }

    private function completeLessonRecord(LessonProgress $progress): void
    {
        $progress->progress_percent = 100;
        $progress->completed_at ??= now();
        $progress->last_accessed_at = now();
    }

    private function enrollmentOrNull(Course $course, User $student): ?Enrollment
    {
        return Enrollment::where('student_id', $student->id)
            ->where('course_id', $course->id)
            ->first();
    }
}
