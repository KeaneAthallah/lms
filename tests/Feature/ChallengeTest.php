<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengeTest extends TestCase
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
        ]);
    }

    private function makeMasteredConcept(User $student, int $questionCount = 5): array
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

        $correctOptionIds = [];
        foreach ($questions as $question) {
            $correct = QuizOption::factory()->correct()->create([
                'quiz_question_id' => $question->id,
                'sort_order' => 1,
            ]);
            QuizOption::factory()->create([
                'quiz_question_id' => $question->id,
                'sort_order' => 2,
            ]);
            $correctOptionIds[$question->id] = $correct->id;
        }

        $this->enroll($student, $course);
        $this->completeLesson($student, $lesson);

        QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => 9.00,
            'score_percentage' => 90.00,
            'passed' => true,
            'submitted_at' => now(),
        ]);

        return [$section, $questions, $correctOptionIds];
    }

    public function test_challenge_builds_five_real_questions_from_a_mastered_concept(): void
    {
        $student = User::factory()->student()->create();
        [$section, $questions, $correctOptionIds] = $this->makeMasteredConcept($student);

        $response = $this->actingAs($student)
            ->getJson('/api/learning/challenge')
            ->assertOk();

        $payload = $response->json('data');

        $this->assertSame($section->title, $payload['concept']);
        $this->assertSame(5, $payload['total_questions']);
        $this->assertCount(5, $payload['questions']);
        $this->assertCount(2, $payload['questions'][0]['options']);

        foreach ($payload['questions'] as $question) {
            $this->assertArrayHasKey('question_text', $question);
            $this->assertArrayNotHasKey('is_correct', $question);
            foreach ($question['options'] as $option) {
                $this->assertArrayNotHasKey('is_correct', $option);
                $this->assertArrayNotHasKey('explanation', $option);
            }
        }
    }

    public function test_challenge_is_null_when_no_concept_is_mastered(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->getJson('/api/learning/challenge')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_challenge_grading_returns_results_without_mutating_attempts_or_grades(): void
    {
        $student = User::factory()->student()->create();
        [$section, $questions, $correctOptionIds] = $this->makeMasteredConcept($student);

        $attemptsBefore = QuizAttempt::where('student_id', $student->id)->count();

        $answers = collect($correctOptionIds)->map(function (int $optionId, int $questionId): array {
            return ['question_id' => $questionId, 'answer' => $optionId];
        })->values()->all();

        $response = $this->actingAs($student)
            ->postJson('/api/learning/challenge/submit', [
                'questions' => array_keys($correctOptionIds),
                'answers' => $answers,
            ])
            ->assertOk();

        $this->assertSame(5, $response->json('data.correct_count'));
        $this->assertSame(100, $response->json('data.percent'));

        $this->assertSame($attemptsBefore, QuizAttempt::where('student_id', $student->id)->count());
        $this->assertSame(0, Grade::count());
    }

    public function test_challenge_grading_flags_wrong_answers(): void
    {
        $student = User::factory()->student()->create();
        [$section, $questions, $correctOptionIds] = $this->makeMasteredConcept($student);

        $wrongAnswers = [];
        foreach ($questions as $question) {
            $wrong = $question->options()->where('is_correct', false)->firstOrFail();
            $wrongAnswers[] = ['question_id' => $question->id, 'answer' => $wrong->id];
        }

        $response = $this->actingAs($student)
            ->postJson('/api/learning/challenge/submit', [
                'questions' => $questions->pluck('id')->all(),
                'answers' => $wrongAnswers,
            ])
            ->assertOk();

        $this->assertSame(0, $response->json('data.correct_count'));
        $this->assertArrayHasKey('correct_answer_text', $response->json('data.questions.0'));
        $this->assertStringContainsString('Worth a reset', $response->json('data.summary'));
    }
}
