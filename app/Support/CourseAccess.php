<?php

namespace App\Support;

use App\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Per-request memoised answers to "which courses may this user learn?".
 *
 * Every policy used to answer this with its own `Enrollment` query, and every
 * `hasRole()` call cost another query, so authorising a page of lessons cost
 * two queries per lesson. The answers are cached on the request attribute bag,
 * which is rebuilt for every request (and for every test), so a memo can never
 * outlive the data it was derived from.
 *
 * Write paths that change the answer call {@see flush()}.
 */
class CourseAccess
{
    private const ENROLLED_KEY = 'lms.access.enrolled_course_ids';

    private const OWNED_KEY = 'lms.access.owned_course_ids';

    public function __construct(private readonly Request $request) {}

    /**
     * Course ids the student is actively enrolled in (cancelled is excluded).
     *
     * @return array<int, int>
     */
    public function enrolledCourseIds(User $user): array
    {
        $key = self::ENROLLED_KEY.'.'.$user->getKey();

        if (! $this->request->attributes->has($key)) {
            $ids = Enrollment::query()
                ->where('student_id', $user->getKey())
                ->where('status', '!=', EnrollmentStatus::Cancelled->value)
                ->pluck('course_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->request->attributes->set($key, $ids);
        }

        return $this->request->attributes->get($key);
    }

    /**
     * Course ids the user is the instructor of.
     *
     * @return array<int, int>
     */
    public function ownedCourseIds(User $user): array
    {
        $key = self::OWNED_KEY.'.'.$user->getKey();

        if (! $this->request->attributes->has($key)) {
            $ids = Course::query()
                ->where('instructor_id', $user->getKey())
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->request->attributes->set($key, $ids);
        }

        return $this->request->attributes->get($key);
    }

    public function isEnrolled(User $user, int $courseId): bool
    {
        return in_array($courseId, $this->enrolledCourseIds($user), true);
    }

    public function isOwner(User $user, int $courseId): bool
    {
        return in_array($courseId, $this->ownedCourseIds($user), true);
    }

    /**
     * May the user open the learning experience for this course at all?
     *
     * Owners and admins are included so they can review and preview their own
     * curriculum. This is deliberately *not* the rule for taking a graded
     * assessment — see {@see canAttemptAssessment()}.
     */
    public function canLearn(User $user, Course $course): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $courseId = (int) $course->getKey();

        return $this->isOwner($user, $courseId) || $this->isEnrolled($user, $courseId);
    }

    /**
     * May the user record a graded attempt for this course?
     *
     * Enrollment only. Owners and admins can read course content, but letting
     * them sit a quiz or hand in an assignment would create attempts on their
     * own gradebook, so the authorship privileges from {@see canLearn()} must
     * not leak into this check.
     */
    public function canAttemptAssessment(User $user, Course $course): bool
    {
        return $this->isEnrolled($user, (int) $course->getKey());
    }

    /**
     * May the user read the body and media of this lesson?
     *
     * This is the paywall boundary: curriculum structure (titles, types,
     * durations) is public, lesson bodies are not. Unpublished lessons stay
     * visible to their author so a course can be previewed while it is built.
     */
    public function canViewLessonContent(User $user, Lesson $lesson): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $courseId = (int) $lesson->course_id;

        $ownsCourse = $this->isOwner($user, $courseId);
        $isEnrolled = $this->isEnrolled($user, $courseId);

        if (! $ownsCourse && ! $isEnrolled) {
            return false;
        }

        return $ownsCourse || (bool) $lesson->is_published;
    }

    /**
     * Drop the memo. Call after anything that changes enrollments or
     * course ownership mid-request.
     */
    public function flush(): void
    {
        foreach (array_keys($this->request->attributes->all()) as $key) {
            if (str_starts_with((string) $key, 'lms.access.')) {
                $this->request->attributes->remove($key);
            }
        }
    }
}
