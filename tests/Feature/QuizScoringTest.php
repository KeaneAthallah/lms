<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizScoringTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuiz(int $passingScore = 50): array
    {
        $student = User::factory()->student()->create();
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'type' => 'quiz',
            'is_published' => true,
        ]);

        $quiz = Quiz::factory()->create([
            'course_id' => $course->id,
            'passing_score' => $passingScore,
            'attempts_allowed' => 2,
            'status' => 'active',
        ]);

        $lesson->update(['quiz_id' => $quiz->id]);

        $correct = QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'type' => 'multiple_choice', 'points' => 1, 'sort_order' => 1]);
        $correctOption = QuizOption::factory()->create(['quiz_question_id' => $correct->id, 'is_correct' => true]);
        QuizOption::factory()->create(['quiz_question_id' => $correct->id, 'is_correct' => false]);

        $wrong = QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'type' => 'multiple_choice', 'points' => 1, 'sort_order' => 2]);
        QuizOption::factory()->create(['quiz_question_id' => $wrong->id, 'is_correct' => true]);
        $wrongOption = QuizOption::factory()->create(['quiz_question_id' => $wrong->id, 'is_correct' => false]);

        (new EnrollmentService)->enroll($student, $course);

        return compact('student', 'course', 'lesson', 'quiz', 'correct', 'correctOption', 'wrong', 'wrongOption');
    }

    public function test_quiz_is_scored_and_passing_completes_the_lesson(): void
    {
        $q = $this->makeQuiz();

        $attempt = $this->actingAs($q['student'])
            ->postJson("/api/quizzes/{$q['quiz']->id}/start")
            ->assertStatus(201)
            ->json('attempt');

        $this->actingAs($q['student'])
            ->postJson("/api/quiz-attempts/{$attempt['id']}/submit", [
                'questions' => [
                    ['question_id' => $q['correct']->id, 'answer' => $q['correctOption']->id],
                    ['question_id' => $q['wrong']->id, 'answer' => $q['wrongOption']->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('attempt.passed', true)
            ->assertJsonPath('attempt.score_percentage', 50)
            ->assertJsonPath('questions.0.is_correct', true)
            ->assertJsonPath('questions.1.is_correct', false);

        $this->assertDatabaseHas('grades', [
            'source_type' => 'App\\Models\\QuizAttempt',
            'source_id' => $attempt['id'],
            'percentage' => 50,
        ]);

        $progress = LessonProgress::where('student_id', $q['student']->id)->where('lesson_id', $q['lesson']->id)->first();
        $this->assertNotNull($progress);
        $this->assertTrue($progress->isCompleted());
    }

    public function test_failing_score_is_recorded_without_completing_the_lesson(): void
    {
        $q = $this->makeQuiz(90);

        $start = $this->actingAs($q['student'])->postJson("/api/quizzes/{$q['quiz']->id}/start")->assertStatus(201)->json('attempt');

        $this->actingAs($q['student'])
            ->postJson("/api/quiz-attempts/{$start['id']}/submit", [
                'questions' => [
                    ['question_id' => $q['correct']->id, 'answer' => $q['correctOption']->id],
                    ['question_id' => $q['wrong']->id, 'answer' => $q['wrongOption']->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('attempt.passed', false);

        $this->assertDatabaseHas('grades', ['source_id' => $start['id'], 'percentage' => 50]);

        $progress = LessonProgress::where('student_id', $q['student']->id)->where('lesson_id', $q['lesson']->id)->first();
        $this->assertNull($progress?->completed_at);
    }

    public function test_attempt_limit_is_enforced(): void
    {
        $q = $this->makeQuiz();
        $q['quiz']->update(['attempts_allowed' => 1]);

        $student = $q['student'];

        $first = $this->actingAs($student)->postJson("/api/quizzes/{$q['quiz']->id}/start")->assertStatus(201)->json('attempt');

        $this->actingAs($student)
            ->postJson("/api/quiz-attempts/{$first['id']}/submit", [
                'questions' => [
                    ['question_id' => $q['correct']->id, 'answer' => $q['correctOption']->id],
                    ['question_id' => $q['wrong']->id, 'answer' => $q['wrongOption']->id],
                ],
            ])
            ->assertOk();

        $this->actingAs($student)
            ->postJson("/api/quizzes/{$q['quiz']->id}/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors('attempts');
    }

    public function test_a_completed_attempt_cannot_be_submitted_twice(): void
    {
        $q = $this->makeQuiz();

        $attempt = $this->actingAs($q['student'])->postJson("/api/quizzes/{$q['quiz']->id}/start")->assertStatus(201)->json('attempt');

        $payload = [
            'questions' => [
                ['question_id' => $q['correct']->id, 'answer' => $q['correctOption']->id],
                ['question_id' => $q['wrong']->id, 'answer' => $q['wrongOption']->id],
            ],
        ];

        $this->actingAs($q['student'])->postJson("/api/quiz-attempts/{$attempt['id']}/submit", $payload)->assertOk();

        $this->actingAs($q['student'])
            ->postJson("/api/quiz-attempts/{$attempt['id']}/submit", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('attempt');
    }
}
