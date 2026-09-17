<?php

namespace App\Services;

use App\CourseStatus;
use App\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

/**
 * Builds the informational roadmap for a course from its declared
 * prerequisites. Purely advisory - it never blocks enrollment or grading.
 */
class CourseRoadmapService
{
    private const STATE_COMPLETED = 'completed';

    private const STATE_IN_PROGRESS = 'in_progress';

    private const STATE_NOT_STARTED = 'not_started';

    /**
     * @return array<string, mixed>
     */
    public function roadmapFor(User $student, Course $course): array
    {
        $prerequisites = $course->prerequisiteCourses()->get(['courses.id', 'courses.title', 'courses.slug', 'courses.level', 'courses.status']);

        $entries = [];

        if ($prerequisites->isNotEmpty()) {
            $courseIds = $prerequisites->pluck('id');
            $certifiedIds = Certificate::where('student_id', $student->id)
                ->whereIn('course_id', $courseIds)
                ->pluck('course_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $enrollments = Enrollment::where('student_id', $student->id)
                ->whereIn('course_id', $courseIds)
                ->where('status', '!=', EnrollmentStatus::Cancelled->value)
                ->get(['course_id', 'status', 'progress_percent', 'completed_at', 'enrolled_at']);

            foreach ($prerequisites as $prerequisite) {
                $enrollment = $enrollments->first(fn ($row) => (int) $row->course_id === (int) $prerequisite->id);
                $isCertified = in_array((int) $prerequisite->id, $certifiedIds, true);

                $state = $this->stateFor($enrollment, $isCertified);

                $entries[] = [
                    'course' => [
                        'id' => $prerequisite->id,
                        'title' => $prerequisite->title,
                        'slug' => $prerequisite->slug,
                        'level' => $prerequisite->level,
                        'published' => $prerequisite->status === CourseStatus::Published,
                    ],
                    'state' => $state,
                    'progress_percent' => $isCertified ? 100 : (int) ($enrollment?->progress_percent ?? 0),
                    'cta' => $this->ctaFor($prerequisite, $state),
                ];
            }
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'method' => 'deterministic_rules',
            'course' => ['id' => $course->id, 'title' => $course->title, 'slug' => $course->slug],
            'state' => $this->overallState($entries),
            'prerequisites' => $entries,
        ];
    }

    /**
     * @return string
     */
    private function stateFor(?Enrollment $enrollment, bool $isCertified)
    {
        if ($isCertified || ($enrollment !== null && ($enrollment->isCompleted() || (int) $enrollment->progress_percent >= 100))) {
            return self::STATE_COMPLETED;
        }

        if ($enrollment !== null && (int) $enrollment->progress_percent > 0) {
            return self::STATE_IN_PROGRESS;
        }

        return self::STATE_NOT_STARTED;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function overallState(array $entries): string
    {
        if ($entries === []) {
            return 'none';
        }

        if (collect($entries)->every(fn (array $entry) => $entry['state'] === self::STATE_COMPLETED)) {
            return 'ready';
        }

        if (collect($entries)->contains(fn (array $entry) => $entry['state'] === self::STATE_IN_PROGRESS)) {
            return 'in_progress';
        }

        return 'not_started';
    }

    /**
     * @return array<string, string>
     */
    private function ctaFor(Course $prerequisite, string $state): array
    {
        return match ($state) {
            self::STATE_COMPLETED => ['to' => "/courses/{$prerequisite->slug}", 'label' => 'View course'],
            self::STATE_IN_PROGRESS => ['to' => "/learn/{$prerequisite->slug}", 'label' => 'Continue prerequisite'],
            default => ['to' => "/courses/{$prerequisite->slug}", 'label' => 'Start prerequisite'],
        };
    }
}
