<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizDiagnosisApiTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(User $student, Course $course): void
    {
        (new EnrollmentService)->enroll($student, $course);
    }

    private function makeBoundQuiz(int $questionCount = 2): array
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $quiz = Quiz::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'quiz_id' => $quiz->id,
            'type' => 'quiz',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $questions = collect(range(1, $questionCount))->map(fn (int $index): QuizQuestion => QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'sort_order' => $index,
        ]));

        $options = $questions->flatMap(function (QuizQuestion $question): array {
            $correct = QuizOption::factory()->correct()->create(['quiz_question_id' => $question->id, 'sort_order' => 1]);
            $wrong = QuizOption::factory()->create(['quiz_question_id' => $question->id, 'sort_order' => 2, 'explanation' => 'This does not apply here.']);

            return [$correct, $wrong];
        });

        return [$course, $section, $lesson, $quiz, $questions, $options];
    }

    public function test_weak_attempt_is_diagnosed_with_recovery_path(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section, $lesson, $quiz, $questions] = $this->makeBoundQuiz();
        $this->enroll($student, $course);

        $attempt = QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 0.00,
            'score_percentage' => 0.00,
            'passed' => false,
            'submitted_at' => now(),
        ]);

        foreach ($questions as $question) {
            $wrong = $question->options()->where('is_correct', false)->firstOrFail();
            QuizAnswer::factory()->create([
                'quiz_attempt_id' => $attempt->id,
                'quiz_question_id' => $question->id,
                'answer' => (string) $wrong->id,
                'is_correct' => false,
                'points_earned' => 0,
            ]);
        }

        $this->actingAs($student)
            ->getJson("/api/quizzes/{$quiz->id}/recovery")
            ->assertOk()
            ->assertJsonPath('data.has_attempt', true)
            ->assertJsonPath('data.diagnosis.concept', $section->title)
            ->assertJsonPath('data.diagnosis.accuracy', 0)
            ->assertJsonPath('data.diagnosis.status', 'review')
            ->assertJsonPath('data.diagnosis.needs_recovery', true)
            ->assertJsonCount(2, 'data.diagnosis.misconceptions')
            ->assertJsonCount(2, 'data.diagnosis.recommendations')
            ->assertJsonPath('data.diagnosis.recommendations.0.type', 'review');
    }

    public function test_strong_attempt_is_diagnosed_without_recovery(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section, $lesson, $quiz, $questions] = $this->makeBoundQuiz();
        $this->enroll($student, $course);

        $attempt = QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 10.00,
            'score_percentage' => 100.00,
            'passed' => true,
            'submitted_at' => now(),
        ]);

        foreach ($questions as $question) {
            $correct = $question->options()->where('is_correct', true)->firstOrFail();
            QuizAnswer::factory()->create([
                'quiz_attempt_id' => $attempt->id,
                'quiz_question_id' => $question->id,
                'answer' => (string) $correct->id,
                'is_correct' => true,
                'points_earned' => 1,
            ]);
        }

        $this->actingAs($student)
            ->getJson("/api/quizzes/{$quiz->id}/recovery")
            ->assertOk()
            ->assertJsonPath('data.has_attempt', true)
            ->assertJsonPath('data.diagnosis.accuracy', 100)
            ->assertJsonPath('data.diagnosis.status', 'strong')
            ->assertJsonPath('data.diagnosis.needs_recovery', false)
            ->assertJsonCount(0, 'data.diagnosis.misconceptions');
    }

    public function test_recovery_has_attempt_false_when_student_never_took_the_quiz(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section, $lesson, $quiz] = $this->makeBoundQuiz();
        $this->enroll($student, $course);

        $this->actingAs($student)
            ->getJson("/api/quizzes/{$quiz->id}/recovery")
            ->assertOk()
            ->assertJsonPath('data.has_attempt', false);
    }

    public function test_recovery_is_forbidden_for_students_not_enrolled_in_the_course(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section, $lesson, $quiz, $questions, $options] = $this->makeBoundQuiz();

        $attempt = QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 0.00,
            'score_percentage' => 10.00,
            'passed' => false,
            'submitted_at' => now(),
        ]);

        $intruder = User::factory()->student()->create();

        $this->actingAs($intruder)
            ->getJson("/api/quizzes/{$quiz->id}/recovery")
            ->assertForbidden();
    }

    public function test_readiness_is_not_ready_until_preceding_lessons_are_completed(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section] = $this->makeBoundQuiz();

        $preceding = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'type' => 'text',
            'sort_order' => 0,
            'is_published' => true,
        ]);
        $this->enroll($student, $course);

        $quiz = $course->quizzes()->first();
        $response = $this->actingAs($student)
            ->getJson("/api/quizzes/{$quiz->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.state', 'not_ready');

        $this->assertStringContainsString('before attempting', $response->json('data.reason'));
    }

    public function test_readiness_is_ready_after_all_preceding_lessons_are_completed(): void
    {
        $student = User::factory()->student()->create();
        [$course, $section, $lesson, $quiz] = $this->makeBoundQuiz();
        $this->enroll($student, $course);

        LessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'progress_percent' => 100,
            'completed_at' => now(),
        ]);

        $this->actingAs($student)
            ->getJson("/api/quizzes/{$quiz->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.state', 'ready');
    }

    public function test_readiness_is_unknown_for_a_quiz_without_a_lesson(): void
    {
        $student = User::factory()->student()->create();
        $course = Course::factory()->create();
        $this->enroll($student, $course);

        $quiz = Quiz::factory()->create(['course_id' => $course->id]);

        $this->actingAs($student)
            ->getJson("/api/quizzes/{$quiz->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.state', 'unknown')
            ->assertJsonPath('data.lesson', null);
    }
}
