<?php

namespace Tests\Feature;

use App\LessonType;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(int $lessons = 2): array
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);

        $lessonModels = collect(range(1, $lessons))->map(fn (int $order): Lesson => Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => $order,
            'is_published' => true,
        ]));

        return [$course, $lessonModels];
    }

    private function enroll(User $student, Course $course): void
    {
        (new EnrollmentService)->enroll($student, $course);
    }

    private function completeLesson(User $student, Lesson $lesson, mixed $completedAt = null): void
    {
        LessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $lesson->course_id,
            'lesson_id' => $lesson->id,
            'progress_percent' => 100,
            'completed_at' => $completedAt ?? now(),
            'last_accessed_at' => now(),
        ]);
    }

    public function test_insights_are_empty_for_a_student_with_no_activity(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.method', 'deterministic_rules')
            ->assertJsonPath('data.summary.active_courses', 0)
            ->assertJsonPath('data.summary.reviews_count', 0)
            ->assertJsonPath('data.summary.recommendations_count', 0)
            ->assertJsonPath('data.summary.momentum.label', 'No activity yet')
            ->assertJsonPath('data.focus', null)
            ->assertJsonCount(0, 'data.reviews')
            ->assertJsonCount(0, 'data.recommendations')
            ->assertJsonCount(0, 'data.quiz_readiness')
            ->assertJsonCount(0, 'data.study_plan');
    }

    public function test_insights_require_authentication(): void
    {
        $this->getJson('/api/learning-insights')->assertStatus(401);
    }

    public function test_next_lesson_is_recommended_after_completing_preceding_lessons(): void
    {
        $student = User::factory()->student()->create();

        [$course, $lessons] = $this->makeCourse(3);
        $this->enroll($student, $course);
        $this->completeLesson($student, $lessons[0]);
        $this->completeLesson($student, $lessons[1]);

        $response = $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.summary.recommendations_count', 1)
            ->assertJsonPath('data.recommendations.0.id', $lessons[2]->id)
            ->assertJsonPath('data.recommendations.0.kind', 'continue')
            ->assertJsonPath('data.focus.type', 'continue');

        $this->assertStringContainsString('preceding 2 lessons', $response->json('data.recommendations.0.reason'));
        $this->assertSame("/learn/{$course->slug}/{$lessons[2]->id}", $response->json('data.recommendations.0.cta.to'));
    }

    public function test_low_quiz_score_generates_a_high_priority_review(): void
    {
        $student = User::factory()->student()->create();

        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $quiz = Quiz::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'quiz_id' => $quiz->id,
            'type' => LessonType::Quiz,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->enroll($student, $course);
        $this->completeLesson($student, $lesson);

        QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 9.00,
            'score_percentage' => 45.00,
            'passed' => false,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.summary.reviews_count', 1)
            ->assertJsonPath('data.reviews.0.id', $lesson->id)
            ->assertJsonPath('data.reviews.0.priority', 'high')
            ->assertJsonPath('data.reviews.0.evidence', 'Quiz result: 45%')
            ->assertJsonPath('data.focus.type', 'review');

        $this->assertStringContainsString('45%', $response->json('data.reviews.0.reason'));
    }

    public function test_good_quiz_score_does_not_generate_a_review(): void
    {
        $student = User::factory()->student()->create();

        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $quiz = Quiz::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'quiz_id' => $quiz->id,
            'type' => LessonType::Quiz,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->enroll($student, $course);
        $this->completeLesson($student, $lesson);

        QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 18.00,
            'score_percentage' => 90.00,
            'passed' => true,
            'submitted_at' => now(),
        ]);

        $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.summary.reviews_count', 0)
            ->assertJsonCount(0, 'data.reviews')
            ->assertJsonPath('data.focus', null);
    }

    public function test_recommendations_are_scoped_to_the_students_courses(): void
    {
        $student = User::factory()->student()->create();

        [$courseA, $lessonsA] = $this->makeCourse(3);
        [$courseB, $lessonsB] = $this->makeCourse(2);

        $courseC = Course::factory()->create();
        $sectionC = CourseSection::factory()->create(['course_id' => $courseC->id]);
        $lessonC = Lesson::factory()->create([
            'course_id' => $courseC->id,
            'section_id' => $sectionC->id,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->enroll($student, $courseA);
        $this->enroll($student, $courseB);
        $this->completeLesson($student, $lessonsA[0]);
        $this->completeLesson($student, $lessonsA[1]);

        $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.summary.active_courses', 2)
            ->assertJsonPath('data.summary.recommendations_count', 2)
            ->assertJsonPath('data.recommendations.0.id', $lessonsA[2]->id);

        $payload = $this->actingAs($student)->getJson('/api/learning-insights')->json('data');
        $recommendedIds = collect($payload['recommendations'])->pluck('lesson.id')->all();

        $this->assertContains($lessonsA[2]->id, $recommendedIds);
        $this->assertContains($lessonsB[0]->id, $recommendedIds);
        $this->assertNotContains($lessonC->id, $recommendedIds);
    }

    public function test_student_only_sees_their_own_insights(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();

        [$courseA, $lessonsA] = $this->makeCourse(2);
        [$courseB, $lessonsB] = $this->makeCourse(1);

        $this->enroll($studentA, $courseA);
        $this->completeLesson($studentA, $lessonsA[0]);

        $this->enroll($studentB, $courseB);

        $payload = $this->actingAs($studentA)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.summary.active_courses', 1)
            ->assertJsonPath('data.recommendations.0.lesson.id', $lessonsA[1]->id)
            ->json('data');

        $sections = array_merge($payload['reviews'], $payload['recommendations'], $payload['quiz_readiness'], $payload['study_plan']);

        $mentionedCourseIds = collect($sections)->pluck('lesson.course.id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $mentionedLessonIds = collect($sections)->pluck('lesson.id')->filter()->unique()->map(fn ($id) => (int) $id)->all();

        $this->assertNotContains($courseB->id, $mentionedCourseIds);
        $this->assertNotContains((int) $lessonsB[0]->id, $mentionedLessonIds);
        $this->assertContains((int) $courseA->id, $mentionedCourseIds);
    }

    public function test_quiz_is_not_ready_until_preceding_lessons_are_completed(): void
    {
        $student = User::factory()->student()->create();

        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'type' => LessonType::Text,
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => 2,
            'is_published' => true,
        ]);

        $quiz = Quiz::factory()->create(['course_id' => $course->id, 'title' => 'Chapter quiz']);
        $lesson->update(['type' => LessonType::Quiz, 'quiz_id' => $quiz->id]);

        $this->enroll($student, $course);

        $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.quiz_readiness.0.state', 'not_ready')
            ->assertJsonPath('data.quiz_readiness.0.lesson.id', $lesson->id)
            ->assertJsonPath('data.quiz_readiness.0.quiz.title', 'Chapter quiz');
    }

    public function test_quiz_is_ready_when_all_preceding_lessons_are_completed_without_gaps(): void
    {
        $student = User::factory()->student()->create();

        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $preceding = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'type' => LessonType::Text,
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => 2,
            'is_published' => true,
        ]);

        $quiz = Quiz::factory()->create(['course_id' => $course->id]);
        $lesson->update(['type' => LessonType::Quiz, 'quiz_id' => $quiz->id]);

        $this->enroll($student, $course);
        $this->completeLesson($student, $preceding);

        $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.quiz_readiness.0.state', 'ready');
    }

    public function test_quiz_is_preparing_when_preceding_assessment_belies_readiness(): void
    {
        $student = User::factory()->student()->create();

        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);

        $precedingQuiz = Quiz::factory()->create(['course_id' => $course->id]);
        $preceding = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'quiz_id' => $precedingQuiz->id,
            'type' => LessonType::Quiz,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $nextQuiz = Quiz::factory()->create(['course_id' => $course->id]);
        $next = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'quiz_id' => $nextQuiz->id,
            'type' => LessonType::Quiz,
            'sort_order' => 2,
            'is_published' => true,
        ]);

        $this->enroll($student, $course);
        $this->completeLesson($student, $preceding);

        QuizAttempt::factory()->completed()->create([
            'quiz_id' => $precedingQuiz->id,
            'student_id' => $student->id,
            'score' => 5.00,
            'score_percentage' => 50.00,
            'passed' => true,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.quiz_readiness.0.state', 'preparing')
            ->assertJsonPath('data.quiz_readiness.0.lesson.id', $next->id);

        $this->assertStringContainsString('50%', $response->json('data.quiz_readiness.0.reason'));
    }

    public function test_study_plan_uses_real_lesson_durations(): void
    {
        $student = User::factory()->student()->create();

        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);

        $completed = Lesson::factory()->video()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => 1,
            'is_published' => true,
            'duration_seconds' => 600,
        ]);
        $next = Lesson::factory()->video()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'sort_order' => 2,
            'is_published' => true,
            'duration_seconds' => 300,
        ]);

        $this->enroll($student, $course);
        $this->completeLesson($student, $completed);

        $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->assertJsonPath('data.recommendations.0.lesson.estimated_minutes', 5)
            ->assertJsonPath('data.recommendations.0.lesson.estimated', false)
            ->assertJsonPath('data.study_plan.0.action', 'Continue')
            ->assertJsonPath('data.study_plan.0.lesson.id', $next->id)
            ->assertJsonPath('data.study_plan.0.minutes', 5)
            ->assertJsonPath('data.study_plan.0.estimated', false)
            ->assertJsonPath('data.summary.study_plan_total_minutes', 5);
    }

    public function test_the_momentum_window_counts_only_the_last_seven_days(): void
    {
        $student = User::factory()->student()->create();
        [$course, $lessons] = $this->makeCourse(3);
        $this->enroll($student, $course);

        $this->completeLesson($student, $lessons->first(), now()->subDays(20));
        $this->completeLesson($student, $lessons->get(1), now()->subDay());
        $this->completeLesson($student, $lessons->get(2), now());

        $this->attempt($student, $this->quiz($course), now()->subDays(9));
        $this->attempt($student, $this->quiz($course), now()->subDays(2));
        $this->submission($student, $this->assignment($course), now()->subDays(30));
        $this->submission($student, $this->assignment($course), now()->subHours(3));

        $momentum = $this->momentum($student);

        $this->assertSame(2, $momentum['lessons_completed_7d'], 'A lesson completed 20 days ago is not this week.');
        $this->assertSame(1, $momentum['quiz_attempts_7d']);
        $this->assertSame(1, $momentum['submissions_7d'], 'Only the graded submission counts, and only this week.');
    }

    public function test_a_streak_counts_consecutive_active_days_not_events(): void
    {
        $student = User::factory()->student()->create();
        [$course, $lessons] = $this->makeCourse(4);
        $this->enroll($student, $course);

        $this->completeLesson($student, $lessons->get(0), now());
        $this->completeLesson($student, $lessons->get(1), now()->subDay());
        $this->completeLesson($student, $lessons->get(2), now()->subDays(2));
        $this->completeLesson($student, $lessons->get(3), now()->subDays(3));

        // Several events on one day are still one day of a streak.
        $this->attempt($student, $this->quiz($course), now());

        $this->assertSame(4, $this->momentum($student)['streak_days']);
    }

    public function test_a_streak_breaks_on_a_day_with_no_activity(): void
    {
        $student = User::factory()->student()->create();
        [$course, $lessons] = $this->makeCourse(3);
        $this->enroll($student, $course);

        $this->completeLesson($student, $lessons->get(0), now());
        $this->completeLesson($student, $lessons->get(1), now()->subDay());
        // Nothing on the day before that.
        $this->completeLesson($student, $lessons->get(2), now()->subDays(4));

        $this->assertSame(2, $this->momentum($student)['streak_days']);
    }

    public function test_a_streak_survives_a_day_that_is_not_over_yet(): void
    {
        $student = User::factory()->student()->create();
        [$course, $lessons] = $this->makeCourse(3);
        $this->enroll($student, $course);

        $this->completeLesson($student, $lessons->get(0), now()->subDay());
        $this->completeLesson($student, $lessons->get(1), now()->subDays(2));

        $this->assertSame(
            2,
            $this->momentum($student)['streak_days'],
            'A streak is still alive until a whole day is missed.'
        );
    }

    public function test_a_streak_that_ended_two_days_ago_is_not_a_streak(): void
    {
        $student = User::factory()->student()->create();
        [$course, $lessons] = $this->makeCourse(2);
        $this->enroll($student, $course);

        $this->completeLesson($student, $lessons->get(0), now()->subDays(2));
        $this->completeLesson($student, $lessons->get(1), now()->subDays(3));

        $this->assertSame(0, $this->momentum($student)['streak_days']);
    }

    public function test_last_activity_is_the_newest_thing_the_student_did_of_any_kind(): void
    {
        $student = User::factory()->student()->create();
        [$course, $lessons] = $this->makeCourse();
        $this->enroll($student, $course);

        $this->completeLesson($student, $lessons->first(), now()->subDays(4));
        $newest = $this->attempt($student, $this->quiz($course), now()->subHour());

        $this->assertSame(
            $newest->submitted_at->toIso8601String(),
            $this->momentum($student)['last_activity_at'],
            'A quiz attempt is activity, and it is the newest thing here.'
        );
    }

    public function test_last_activity_falls_back_to_the_enrollment_when_nothing_else_is_recorded(): void
    {
        $student = User::factory()->student()->create();
        [$course] = $this->makeCourse();
        $this->enroll($student, $course);

        $lastAccess = now()->subDays(3);
        $student->enrollments()->update(['last_accessed_at' => $lastAccess]);

        $this->assertSame(
            $lastAccess->toIso8601String(),
            $this->momentum($student)['last_activity_at']
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function momentum(User $student): array
    {
        return $this->actingAs($student)
            ->getJson('/api/learning-insights')
            ->assertOk()
            ->json('data.summary.momentum');
    }

    private function quiz(Course $course): Quiz
    {
        return Quiz::factory()->create(['course_id' => $course->id]);
    }

    private function attempt(User $student, Quiz $quiz, mixed $submittedAt): QuizAttempt
    {
        return QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'submitted_at' => $submittedAt,
        ]);
    }

    private function assignment(Course $course): Assignment
    {
        return Assignment::factory()->create(['course_id' => $course->id]);
    }

    private function submission(User $student, Assignment $assignment, mixed $gradedAt): AssignmentSubmission
    {
        return AssignmentSubmission::factory()->graded()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'graded_at' => $gradedAt,
        ]);
    }
}
