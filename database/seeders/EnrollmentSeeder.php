<?php

namespace Database\Seeders;

use App\EnrollmentStatus;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Seeder;

class EnrollmentSeeder extends Seeder
{
    public function run(): void
    {
        $student = fn (string $email): User => User::where('email', $email)->firstOrFail();
        $course = fn (string $slug): Course => Course::where('slug', $slug)->firstOrFail();

        $this->inProgress($student('alex@example.com'), $course('modern-web-development-with-laravel'), 50, [
            'Getting Started',
            'Models & Migration',
        ]);

        $this->complete($student('jordan@example.com'), $course('modern-web-development-with-laravel'));

        $this->inProgress($student('taylor@example.com'), $course('ui-ux-design-fundamentals'), 25, [
            'Design Principles',
        ]);

        $this->inProgress($student('sam@example.com'), $course('data-science-with-python'), 70, [
            'Exploratory Data Analysis',
            'Modeling',
        ]);

        $this->started($student('casey@example.com'), $course('leadership-essentials'));

        $this->inProgress($student('taylor@example.com'), $course('leadership-essentials'), 10, [
            'Building Trust',
        ]);
    }

    private function started(User $student, Course $course): void
    {
        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now()->subDay(),
        ]);
    }

    /**
     * Create partial progress: complete lessons in the given sections, plus the first lesson
     * of the following section as "started".
     */
    private function inProgress(User $student, Course $course, int $targetPercent, array $completedSectionTitles): void
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => $targetPercent,
            'enrolled_at' => now()->subWeeks(3),
            'last_accessed_at' => now(),
        ]);

        $completed = false;
        foreach ($course->sections as $section) {
            foreach ($section->lessons as $lesson) {
                if (in_array($section->title, $completedSectionTitles, true)) {
                    $this->markCompleted($student, $course, $lesson);
                } elseif (! $completed) {
                    $this->markStarted($student, $course, $lesson);
                    $completed = true;
                }
            }
        }

        $this->quizActivity($student, $course);
        $this->assignmentActivity($student, $course, $targetPercent >= 50);
    }

    private function complete(User $student, Course $course): void
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Completed,
            'progress_percent' => 100,
            'enrolled_at' => now()->subWeeks(6),
            'completed_at' => now()->subWeek(),
            'last_accessed_at' => now()->subWeek(),
        ]);

        foreach ($course->sections as $section) {
            foreach ($section->lessons as $lesson) {
                $this->markCompleted($student, $course, $lesson);
            }
        }

        $this->quizActivity($student, $course);
        $this->gradeAssignment($student, $course);
    }

    private function markStarted(User $student, Course $course, Lesson $lesson): void
    {
        LessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'progress_percent' => 10,
            'started_at' => now()->subDay(),
            'last_accessed_at' => now(),
        ]);
    }

    private function markCompleted(User $student, Course $course, Lesson $lesson): void
    {
        $startedAt = now()->subDays(random_int(2, 20));

        LessonProgress::updateOrCreate(
            ['student_id' => $student->id, 'lesson_id' => $lesson->id],
            [
                'course_id' => $course->id,
                'progress_percent' => 100,
                'started_at' => $startedAt,
                'completed_at' => $startedAt->copy()->addMinutes(random_int(5, 60)),
                'last_accessed_at' => $startedAt->copy()->addMinutes(random_int(5, 60)),
            ],
        );
    }

    private function quizActivity(User $student, Course $course): void
    {
        foreach ($course->quizzes as $quiz) {
            $questions = $quiz->questions;

            if ($questions->isEmpty()) {
                continue;
            }

            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'status' => 'completed',
                'started_at' => now()->subDays(3),
                'submitted_at' => now()->subDays(3)->addMinutes(8),
            ]);

            $totalEarned = 0;
            $totalPossible = 0;

            foreach ($questions as $question) {
                $correctOption = $question->options()->where('is_correct', true)->first();
                $chosen = $correctOption !== null && random_int(1, 100) <= 85;
                $earned = $chosen ? (float) $question->points : 0.0;
                $totalEarned += $earned;
                $totalPossible += (float) $question->points;

                QuizAnswer::create([
                    'quiz_attempt_id' => $attempt->id,
                    'quiz_question_id' => $question->id,
                    'answer' => $chosen && $correctOption ? $correctOption->id : 'none',
                    'is_correct' => $chosen,
                    'points_earned' => $earned,
                ]);
            }

            $percentage = $totalPossible > 0 ? round(($totalEarned / $totalPossible) * 100, 2) : 0;

            $attempt->update([
                'score' => $totalEarned,
                'score_percentage' => $percentage,
                'passed' => $percentage >= (float) $quiz->passing_score,
            ]);
        }
    }

    private function assignmentActivity(User $student, Course $course, bool $graded): void
    {
        foreach ($course->assignments as $assignment) {
            $submission = AssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'student_id' => $student->id,
                'content' => "Here is my submission for \"{$assignment->title}\".\n\nI have summarised what I learned in this module and walked through my reasoning step by step. I am happy to adjust anything based on the feedback.",
                'version' => 1,
                'status' => $graded ? 'graded' : 'submitted',
                'submitted_at' => now()->subDays(random_int(1, 4)),
            ]);

            if ($graded) {
                $grade = random_int(78, 98);
                $submission->update([
                    'grade' => $grade,
                    'feedback' => 'Great work! Your reasoning is clear and your examples are well chosen. Keep polishing the structure of the report.',
                    'graded_by' => $course->instructor_id,
                    'graded_at' => now()->subDays(1),
                    'status' => 'graded',
                ]);
            }
        }
    }

    private function gradeAssignment(User $student, Course $course): void
    {
        foreach ($course->assignments as $assignment) {
            $submission = AssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'student_id' => $student->id,
                'content' => "My final submission for \"{$assignment->title}\" — full write-up included.",
                'version' => 1,
                'status' => 'graded',
                'submitted_at' => now()->subWeeks(2),
                'grade' => random_int(88, 99),
                'feedback' => 'Excellent, thorough submission. Your conclusions were well supported by the material.',
                'graded_by' => $course->instructor_id,
                'graded_at' => now()->subWeek(),
            ]);
        }
    }
}
