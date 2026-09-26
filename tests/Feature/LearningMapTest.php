<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningMapTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(User $student, Course $course): void
    {
        (new EnrollmentService)->enroll($student, $course);
    }

    private function completeLesson(User $student, Lesson $lesson): void
    {
        LessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $lesson->course_id,
            'lesson_id' => $lesson->id,
            'progress_percent' => 100,
            'completed_at' => now(),
            'last_accessed_at' => now(),
        ]);
    }

    private function makeCourseWithQuiz(int $lessonCount): array
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $lessons = collect(range(1, $lessonCount))->map(
            fn (int $order): Lesson => Lesson::factory()->create([
                'course_id' => $course->id,
                'section_id' => $section->id,
                'sort_order' => $order,
                'is_published' => true,
            ])
        );

        $quiz = Quiz::factory()->create(['course_id' => $course->id, 'passing_score' => 75]);
        $lessons->last()->update(['quiz_id' => $quiz->id, 'type' => 'quiz']);

        return [$course, $section, $lessons, $quiz];
    }

    public function test_learning_map_is_empty_for_a_student_with_no_enrollments(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->getJson('/api/learning-map')
            ->assertOk()
            ->assertJsonPath('data.method', 'deterministic_rules')
            ->assertJsonPath('data.overall_percent', 0)
            ->assertJsonCount(0, 'data.courses');
    }

    public function test_learning_map_reports_partial_progress_and_next_lesson(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section, $lessons, $quiz] = $this->makeCourseWithQuiz(2);

        $this->enroll($student, $course);
        $this->completeLesson($student, $lessons[0]);

        $this->actingAs($student)
            ->getJson('/api/learning-map')
            ->assertOk()
            ->assertJsonPath('data.courses.0.course.id', $course->id)
            ->assertJsonPath('data.courses.0.progress_percent', 50)
            ->assertJsonPath('data.courses.0.completed_lessons', 1)
            ->assertJsonPath('data.courses.0.total_lessons', 2)
            ->assertJsonPath('data.courses.0.next_lesson.id', $lessons[1]->id)
            ->assertJsonPath('data.courses.0.concepts.0.section_id', $section->id);
    }

    public function test_concept_is_review_when_quiz_best_is_weak(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section, $lessons, $quiz] = $this->makeCourseWithQuiz(2);

        $this->enroll($student, $course);
        $this->completeLesson($student, $lessons[0]);

        QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 4.00,
            'score_percentage' => 40.00,
            'passed' => false,
            'submitted_at' => now(),
        ]);

        $this->actingAs($student)
            ->getJson('/api/learning-map')
            ->assertOk()
            ->assertJsonPath('data.courses.0.concepts.0.status', 'review')
            ->assertJsonPath('data.courses.0.concepts.0.mastery_percent', 44);
    }

    public function test_concept_is_mastered_when_assessment_and_completion_are_high(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section, $lessons, $quiz] = $this->makeCourseWithQuiz(1);

        $this->enroll($student, $course);
        $this->completeLesson($student, $lessons[0]);

        QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 9.50,
            'score_percentage' => 95.00,
            'passed' => true,
            'submitted_at' => now(),
        ]);

        $this->actingAs($student)
            ->getJson('/api/learning-map')
            ->assertOk()
            ->assertJsonPath('data.courses.0.concepts.0.status', 'mastered')
            ->assertJsonPath('data.courses.0.concepts.0.mastery_percent', 97)
            ->assertJsonPath('data.overall_percent', 97);
    }

    public function test_learning_map_never_leaks_other_students_activity(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();

        [$courseA, $sectionA, $lessonsA, $quizA] = $this->makeCourseWithQuiz(2);
        [$courseB, $sectionB, $lessonsB, $quizB] = $this->makeCourseWithQuiz(2);

        $this->enroll($studentA, $courseA);
        $this->enroll($studentB, $courseB);
        $this->completeLesson($studentA, $lessonsA[0]);
        $this->completeLesson($studentB, $lessonsB[0]);

        $payload = $this->actingAs($studentA)
            ->getJson('/api/learning-map')
            ->assertOk()
            ->assertJsonCount(1, 'data.courses')
            ->assertJsonPath('data.courses.0.course.id', $courseA->id)
            ->json('data');

        $mentionedCourseIds = collect($payload['courses'])
            ->pluck('course.id')
            ->merge(collect($payload['courses'])->flatMap(fn (array $course) => collect($course['concepts'])->pluck('course.id')))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $this->assertContains((int) $courseA->id, $mentionedCourseIds);
        $this->assertNotContains((int) $courseB->id, $mentionedCourseIds);
    }

    public function test_learning_map_requires_authentication(): void
    {
        $this->getJson('/api/learning-map')->assertStatus(401);
    }

    public function test_a_passed_quiz_is_not_offered_again_as_upcoming(): void
    {
        $student = User::factory()->student()->create();

        [$course, $section, $lessons, $quiz] = $this->makeCourseWithQuiz(1);

        $this->enroll($student, $course);

        // `loadQuizAttempts` used to omit `passed` from its column list, so the
        // "skip quizzes already passed" guard in LearningMapService was dead code
        // and a passed quiz kept being surfaced as the next thing to do.
        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'status' => 'completed',
            'score' => 10,
            'score_percentage' => 100,
            'passed' => true,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($student)
            ->getJson('/api/learning-map')
            ->assertOk()
            ->assertJsonPath('data.courses.0.upcoming_quiz', null);
    }

    public function test_a_failed_quiz_is_still_offered_as_upcoming(): void
    {
        $student = User::factory()->student()->create();

        [$course, $section, $lessons, $quiz] = $this->makeCourseWithQuiz(1);

        $this->enroll($student, $course);

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'status' => 'completed',
            'score' => 2,
            'score_percentage' => 20,
            'passed' => false,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($student)
            ->getJson('/api/learning-map')
            ->assertOk()
            ->assertJsonPath('data.courses.0.upcoming_quiz.quiz.id', $quiz->id)
            ->assertJsonPath('data.courses.0.upcoming_quiz.quiz.passing_score', 75)
            ->assertJsonPath('data.courses.0.upcoming_quiz.state', 'ready');
    }

    public function test_learning_map_query_count_does_not_grow_with_enrolled_courses(): void
    {
        $student = User::factory()->student()->create();

        // Registered once for the whole test: a `DB::listen` closure captures the
        // counter by reference, so re-registering inside a loop would make every
        // iteration increment the same variable and report compounding totals.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $counts = [];
        $enrolled = 0;

        while ($enrolled < 1) {
            $this->enroll($student, $this->makeCourseWithQuiz(1)[0]);
            $enrolled++;
        }

        // Warm-up request: a cold first request carries one-time setup queries that
        // would otherwise be charged to the first measurement.
        $this->actingAs($student)
            ->getJson('/api/learning-map')
            ->assertOk();

        // The upcoming-quiz card re-derives readiness per course, which previously
        // re-fetched each course's sections, lessons, progress and attempts. Adding
        // courses must not add queries.
        foreach ([1, 4] as $target) {
            while ($enrolled < $target) {
                $this->enroll($student, $this->makeCourseWithQuiz(1)[0]);
                $enrolled++;
            }

            $before = $queries;

            $this->actingAs($student)
                ->getJson('/api/learning-map')
                ->assertOk();

            $counts[$target] = $queries - $before;
        }

        $this->assertSame(
            $counts[1],
            $counts[4],
            "Learning map issued {$counts[1]} queries for 1 course but {$counts[4]} for 4 courses."
        );
    }
}
