<?php

namespace Tests\Feature;

use App\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradebookCategory;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gradebook categories and weights.
 *
 * A category groups assessments that share a weight. The course percentage is
 * then the weighted mean of the buckets the student has a grade in - within a
 * bucket their score is the mean of its graded cells, and an untaken bucket
 * (or assessment) counts for nothing rather than a zero. A course with no
 * categories is one uncategorized bucket of weight 1, the plain mean.
 */
class InstructorGradebookCategoryTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- access

    public function test_only_the_course_owner_or_an_admin_may_manage_categories(): void
    {
        $course = Course::factory()->create();
        $otherInstructor = User::factory()->instructor()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($otherInstructor)
            ->postJson("/api/instructor/courses/{$course->slug}/gradebook-categories", ['name' => 'Quizzes', 'weight' => 60])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/instructor/courses/{$course->slug}/gradebook-categories", ['name' => 'Quizzes', 'weight' => 60])
            ->assertCreated();

        $this->actingAs($course->instructor)
            ->postJson("/api/instructor/courses/{$course->slug}/gradebook-categories", ['name' => 'Assignments', 'weight' => 40])
            ->assertCreated();
    }

    public function test_a_student_cannot_access_gradebook_categories(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook-categories")
            ->assertForbidden();
    }

    // ------------------------------------------------------------- crud

    public function test_a_category_is_created_listed_and_updated(): void
    {
        $course = Course::factory()->create();

        $created = $this->actingAs($course->instructor)
            ->postJson("/api/instructor/courses/{$course->slug}/gradebook-categories", ['name' => 'Quizzes', 'weight' => 60, 'sort_order' => 2])
            ->assertCreated()
            ->json('category');

        $this->assertSame('Quizzes', $created['name']);
        $this->assertSame(60.0, (float) $created['weight']);

        $listed = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook-categories")
            ->assertOk()
            ->json('categories');

        $this->assertCount(1, $listed);
        $this->assertSame("category-{$created['id']}", $listed[0]['key']);

        $updated = $this->actingAs($course->instructor)
            ->putJson("/api/instructor/courses/{$course->slug}/gradebook-categories/{$created['id']}", ['name' => 'Exams', 'weight' => 80])
            ->assertOk()
            ->json('category');

        $this->assertSame('Exams', $updated['name']);
        $this->assertSame(80.0, (float) $updated['weight']);
    }

    public function test_category_names_are_unique_per_course(): void
    {
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();

        $category = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Quizzes']);

        // Same name on another course is fine...
        $this->actingAs($otherCourse->instructor)
            ->postJson("/api/instructor/courses/{$otherCourse->slug}/gradebook-categories", ['name' => 'Quizzes', 'weight' => 50])
            ->assertCreated();

        // ...but a duplicate on the same course is not, including a rename onto it.
        $this->actingAs($course->instructor)
            ->postJson("/api/instructor/courses/{$course->slug}/gradebook-categories", ['name' => 'Quizzes', 'weight' => 30])
            ->assertJsonValidationErrors('name');

        $this->actingAs($course->instructor)
            ->postJson("/api/instructor/courses/{$course->slug}/gradebook-categories", ['name' => 'Assignments', 'weight' => 40])
            ->assertCreated();
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $course = Course::factory()->create();
        $category = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Quizzes']);

        $quiz = $this->quiz($course, 'Midterm');
        $this->actingAs($course->instructor)
            ->patchJson("/api/instructor/courses/{$course->slug}/gradebook-categories/assign", [
                'selections' => [['key' => "quiz-{$quiz->id}", 'category_id' => $category->id]],
            ])
            ->assertOk();

        $this->actingAs($course->instructor)
            ->deleteJson("/api/instructor/courses/{$course->slug}/gradebook-categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        // Sending the assessment back to "uncategorized" unlocks the delete.
        $this->actingAs($course->instructor)
            ->patchJson("/api/instructor/courses/{$course->slug}/gradebook-categories/assign", [
                'selections' => [['key' => "quiz-{$quiz->id}", 'category_id' => null]],
            ])
            ->assertOk();

        $this->actingAs($course->instructor)
            ->deleteJson("/api/instructor/courses/{$course->slug}/gradebook-categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('gradebook_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id, 'category_id' => null]);
    }

    public function test_a_category_from_another_course_is_not_joinable_in_requests(): void
    {
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $foreignCategory = GradebookCategory::factory()->create(['course_id' => $otherCourse->id]);
        $quiz = $this->quiz($course, 'Midterm');

        $this->actingAs($course->instructor)
            ->patchJson("/api/instructor/courses/{$course->slug}/gradebook-categories/assign", [
                'selections' => [['key' => "quiz-{$quiz->id}", 'category_id' => $foreignCategory->id]],
            ])
            ->assertJsonValidationErrors('selections.0.category_id');

        $this->actingAs($course->instructor)
            ->deleteJson("/api/instructor/courses/{$course->slug}/gradebook-categories/{$foreignCategory->id}")
            ->assertNotFound();
    }

    public function test_an_assessment_key_must_belong_to_the_course(): void
    {
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $category = GradebookCategory::factory()->create(['course_id' => $course->id]);
        $foreignQuiz = $this->quiz($otherCourse, 'Foreign');

        $this->actingAs($course->instructor)
            ->patchJson("/api/instructor/courses/{$course->slug}/gradebook-categories/assign", [
                'selections' => [
                    ['key' => "quiz-{$foreignQuiz->id}", 'category_id' => $category->id],
                    ['key' => 'video-7', 'category_id' => null],
                ],
            ])
            ->assertJsonValidationErrors(['selections.0.key', 'selections.1.key']);

        $this->assertDatabaseMissing('quizzes', ['id' => $foreignQuiz->id, 'category_id' => $category->id]);
    }

    public function test_assessments_are_moved_between_buckets_by_key(): void
    {
        $course = Course::factory()->create();
        $quizzes = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Quizzes']);
        $assignments = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Assignments']);

        $quiz = $this->quiz($course, 'Midterm');
        $assignment = $this->assignment($course, 'Essay');

        $this->actingAs($course->instructor)
            ->patchJson("/api/instructor/courses/{$course->slug}/gradebook-categories/assign", [
                'selections' => [
                    ['key' => "quiz-{$quiz->id}", 'category_id' => $quizzes->id],
                    ['key' => "assignment-{$assignment->id}", 'category_id' => $assignments->id],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id, 'category_id' => $quizzes->id]);
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id, 'category_id' => $assignments->id]);

        // The gradebook's columns carry the bucket back, its band included.
        $gradebook = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        foreach ($gradebook['assessments'] as $assessment) {
            $expected = $assessment['type'] === 'quiz' ? $quizzes->id : $assignments->id;
            $this->assertSame($expected, $assessment['category_id']);
            $this->assertSame("category-{$expected}", $assessment['category_key']);
        }
    }

    // --------------------------------------------------------------- weights

    public function test_the_course_grade_is_the_weighted_mean_of_the_graded_buckets(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $quizzes = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Quizzes', 'weight' => 60]);
        $assignments = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Assignments', 'weight' => 40]);

        $quiz = $this->quiz($course, 'Midterm');
        $assignment = $this->assignment($course, 'Essay');

        $this->assign($course, $quiz, $quizzes);
        $this->assign($course, $assignment, $assignments);

        $this->gradeAttempt($course, $quiz, $alice, 9, 10, 90);
        $this->gradeAssignment($course, $assignment, $alice, 80, 100);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        // (60 × 90 + 40 × 80) / (60 + 40) = 86.
        $this->assertSame(86.0, (float) $json['students'][0]['course_percentage']);
        $this->assertSame(90.0, (float) $json['students'][0]['category_percentages']["category-{$quizzes->id}"]);
        $this->assertSame(80.0, (float) $json['students'][0]['category_percentages']["category-{$assignments->id}"]);

        // The categories payload carries the weights and the bucket class average.
        $quizzesPayload = collect($json['categories'])->firstWhere('id', $quizzes->id);
        $this->assertSame(60.0, (float) $quizzesPayload['weight']);
        $this->assertSame(90.0, (float) $quizzesPayload['average']);
    }

    public function test_an_untaken_bucket_does_not_drag_the_denominator_down(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $quizzes = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Quizzes', 'weight' => 60]);
        $assignments = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Assignments', 'weight' => 40]);

        $quiz = $this->quiz($course, 'Midterm');
        $assignment = $this->assignment($course, 'Essay');

        $this->assign($course, $quiz, $quizzes);
        $this->assign($course, $assignment, $assignments);

        $this->gradeAttempt($course, $quiz, $alice, 9, 10, 90);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        // The essay bucket was never touched: 90 × 60 / 60.
        $this->assertSame(90.0, (float) $json['students'][0]['course_percentage']);
        $this->assertNull($json['students'][0]['category_percentages']["category-{$assignments->id}"]);
    }

    public function test_a_weight_of_zero_lets_a_bucket_opt_out_of_the_course_grade(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $ignored = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Stretch', 'weight' => 0]);
        $quiz = $this->quiz($course, 'Midterm');
        $this->assign($course, $quiz, $ignored);
        $this->gradeAttempt($course, $quiz, $alice, 4, 10, 40);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        // The only graded bucket has weight 0, so there is no weighted grade.
        $this->assertNull($json['students'][0]['course_percentage']);
        $this->assertSame(40.0, (float) $json['students'][0]['cells']["quiz-{$quiz->id}"]['percentage']);
    }

    public function test_uncategorized_assessments_count_with_weight_one(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $quizzes = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Quizzes', 'weight' => 60]);

        $categorized = $this->quiz($course, 'Categorized quiz');
        $uncategorized = $this->quiz($course, 'Stray quiz');
        $this->assign($course, $categorized, $quizzes);

        $this->gradeAttempt($course, $categorized, $alice, 9, 10, 90);
        $this->gradeAttempt($course, $uncategorized, $alice, 7, 10, 70);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        // (60 × 90 + 1 × 70) / (60 + 1).
        $this->assertSame(round((60 * 90 + 70) / 61, 2), (float) $json['students'][0]['course_percentage']);
        $this->assertSame(70.0, (float) $json['students'][0]['category_percentages']['uncategorized']);

        $uncategorizedBucket = collect($json['categories'])->firstWhere('id', null);
        $this->assertSame('Uncategorized', $uncategorizedBucket['name']);
        $this->assertSame(1.0, (float) $uncategorizedBucket['weight']);
        $this->assertSame(70.0, (float) $uncategorizedBucket['average']);
    }

    public function test_a_course_with_no_categories_reports_the_plain_mean(): void
    {
        $course = Course::factory()->create();
        $alice = $this->enroll($course, 'Alice');

        $quiz = $this->quiz($course, 'Midterm');
        $assignment = $this->assignment($course, 'Essay');

        $this->gradeAttempt($course, $quiz, $alice, 9, 10, 90);
        $this->gradeAssignment($course, $assignment, $alice, 80, 100);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        $this->assertCount(1, $json['categories']);
        $this->assertSame('uncategorized', $json['categories'][0]['key']);
        $this->assertSame(1.0, (float) $json['categories'][0]['weight']);
        $this->assertSame(85.0, (float) $json['students'][0]['course_percentage']);
        $this->assertSame(85.0, (float) $json['students'][0]['category_percentages']['uncategorized']);
        $this->assertSame('uncategorized', $json['assessments'][0]['category_key']);
    }

    public function test_columns_are_grouped_by_category_in_category_order(): void
    {
        $course = Course::factory()->create();

        $examBucket = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Exams', 'sort_order' => 0]);
        $quizBucket = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Quizzes', 'sort_order' => 1]);
        $emptyBucket = GradebookCategory::factory()->create(['course_id' => $course->id, 'name' => 'Empty bucket', 'sort_order' => 2]);

        $examQuiz = $this->quiz($course, 'Zleepy exam');
        $stray = $this->quiz($course, 'Aa stray');
        $quizA = $this->quiz($course, 'Quick check');
        $quizB = $this->quiz($course, 'Deep dive');

        $this->assign($course, $examQuiz, $examBucket);
        $this->assign($course, $quizA, $quizBucket);
        $this->assign($course, $quizB, $quizBucket);

        $json = $this->actingAs($course->instructor)
            ->getJson("/api/instructor/courses/{$course->slug}/gradebook")
            ->assertOk()
            ->json();

        $keys = collect($json['assessments'])->pluck('key')->all();

        // Exams bucket, then Quizzes bucket, uncategorized last; title order inside each.
        $this->assertSame(["quiz-{$examQuiz->id}"], array_slice($keys, 0, 1));
        $this->assertSame(["quiz-{$quizB->id}", "quiz-{$quizA->id}"], array_slice($keys, 1, 2));
        $this->assertSame(["quiz-{$stray->id}"], array_slice($keys, 3, 1));

        // The empty bucket still appears in the categories list, just no columns.
        $keysInCategories = collect($json['categories'])->pluck('key')->all();
        $this->assertContains("category-{$emptyBucket->id}", $keysInCategories);
    }

    // ---------------------------------------------------------------- helpers

    private function enroll(Course $course, string $name, string $status = EnrollmentStatus::Active->value): User
    {
        $student = User::factory()->student()->create(['name' => $name]);

        Enrollment::factory()->create([
            'course_id' => $course->id,
            'student_id' => $student->id,
            'status' => $status,
            'progress_percent' => $status === EnrollmentStatus::Completed->value ? 100 : 0,
        ]);

        return $student;
    }

    private function quiz(Course $course, string $title): Quiz
    {
        return Quiz::factory()->create(['course_id' => $course->id, 'title' => $title]);
    }

    private function assignment(Course $course, string $title): Assignment
    {
        return Assignment::factory()->create(['course_id' => $course->id, 'title' => $title]);
    }

    private function assign(Course $course, Quiz|Assignment $assessment, GradebookCategory $category): void
    {
        $assessment->update(['category_id' => $category->id]);
    }

    private function gradeAttempt(Course $course, Quiz $quiz, User $student, float $score, float $maxScore, float $percentage): void
    {
        $attempt = QuizAttempt::factory()->completed()->create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'score' => $score,
            'score_percentage' => $percentage,
            'passed' => $percentage >= 70,
        ]);

        Grade::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'source_type' => QuizAttempt::class,
            'source_id' => $attempt->id,
            'type' => 'quiz',
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'graded_at' => now(),
        ]);
    }

    private function gradeAssignment(Course $course, Assignment $assignment, User $student, float $score, float $maxScore): void
    {
        $submission = AssignmentSubmission::factory()->graded()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'grade' => $score,
        ]);

        Grade::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'source_type' => AssignmentSubmission::class,
            'source_id' => $submission->id,
            'type' => 'assignment',
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => round(($score / $maxScore) * 100, 2),
            'graded_at' => now(),
        ]);
    }
}
