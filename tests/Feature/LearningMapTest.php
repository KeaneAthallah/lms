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

        $quiz = Quiz::factory()->create(['course_id' => $course->id]);
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
}
