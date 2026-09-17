<?php

namespace Database\Seeders;

use App\AssignmentStatus;
use App\CourseStatus;
use App\EnrollmentStatus;
use App\LessonType;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\QuizAttemptStatus;
use App\Role;
use App\SubmissionStatus;
use Database\Seeders\Traits\GeneratesSeedImages;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seed a full year of realistic platform usage on top of the curated seed data:
 * a few hundred students, a broader instructor roster, a real catalogue, and
 * thousands of enrollments, attempts, submissions, and certificates spread
 * across the previous twelve months.
 */
class YearOfUsageSeeder extends Seeder
{
    use GeneratesSeedImages;

    private const STUDENT_COUNT = 200;

    private const EXTRA_INSTRUCTOR_COUNT = 12;

    private const COURSES_PER_CATEGORY = 10;

    private const DRAFT_COUNT = 3;

    private const ARCHIVED_COUNT = 2;

    /** @var array<int, int> instructor ids available to teach new courses */
    private array $instructorIds = [];

    /** @var array<int, int> student ids that will enroll */
    private array $studentIds = [];

    private int $certificateCounter = 10000;

    public function run(): void
    {
        $this->createInstructors();
        $this->createStudents();

        $courses = $this->createCourseCatalogue();

        $this->createYearOfActivity($courses);

        $this->command?->info(sprintf(
            'Seeded a year of usage: %d students, %d instructors, %d courses, %d enrollments.',
            User::whereHas('roles', fn ($q) => $q->where('name', Role::Student->value))->count(),
            User::whereHas('roles', fn ($q) => $q->where('name', Role::Instructor->value))->count(),
            Course::count(),
            Enrollment::count(),
        ));
    }

    private function createInstructors(): void
    {
        $existing = User::whereHas('roles', fn ($q) => $q->where('name', Role::Instructor->value))->pluck('id')->all();
        $this->instructorIds = $existing;

        for ($i = 1; $i <= self::EXTRA_INSTRUCTOR_COUNT; $i++) {
            $name = fake()->name();

            $user = User::create([
                'name' => $name,
                'email' => "instructor{$i}@example.com",
                'headline' => fake()->jobTitle(),
                'bio' => fake()->paragraph(random_int(1, 2)),
                'password' => 'password',
                'email_verified_at' => now()->subMonths(random_int(6, 14)),
                'is_active' => true,
            ]);

            $user->syncRoles([Role::Instructor]);
            $this->withAvatar($user);

            $this->instructorIds[] = $user->id;
        }
    }

    private function createStudents(): void
    {
        $existing = User::whereHas('roles', fn ($q) => $q->where('name', Role::Student->value))->pluck('id')->all();
        $this->studentIds = $existing;

        for ($i = 1; $i <= self::STUDENT_COUNT; $i++) {
            $user = User::create([
                'name' => fake()->name(),
                'email' => "student{$i}@example.com",
                'password' => 'password',
                'email_verified_at' => now()->subMonths(random_int(0, 14))->subDays(random_int(0, 28)),
                'is_active' => true,
            ]);

            $user->syncRoles([Role::Student]);
            $this->withAvatar($user);

            $this->studentIds[] = $user->id;
        }
    }

    /** @return array<int, Course> */
    private function createCourseCatalogue(): array
    {
        $titles = [
            'web-development' => [
                'Laravel API Development in Practice',
                'Frontend Fundamentals with React & TypeScript',
                'SQL and Database Design Essentials',
                'Git & GitHub Workflows',
                'PHP 8 for Absolute Beginners',
                'Docker for Everyday Developers',
                'Writing Tests Your App Can Trust',
                'Building SPAs with Vue 3',
                'REST API Design Patterns',
                'HTTP, Sessions, and Cookies Explained',
            ],
            'ui-ux-design' => [
                'Figma for Interface Designers',
                'Design Systems in Practice',
                'Mobile UX Principles',
                'Information Architecture Basics',
                'Prototyping for Product Teams',
                'User Research Methods That Work',
                'Accessibility in Digital Products',
                'Visual Design Fundamentals',
                'Interaction Design Patterns',
                'UX Writing Essentials',
            ],
            'data-science' => [
                'Python for Data Analysis',
                'Machine Learning with scikit-learn',
                'Data Visualization with Python',
                'SQL for Data Analytics',
                'Statistics for Data Science',
                'Introduction to Deep Learning',
                'The Basics of Big Data',
                'Time Series Analysis',
                'A/B Testing & Experimentation',
                'Data Engineering Foundations',
            ],
            'business' => [
                'Project Management Fundamentals',
                'Financial Modeling in Spreadsheets',
                'Business Communication that Lands',
                'Operations Strategy',
                'Entrepreneurship Essentials',
                'Negotiation Skills Mastery',
                'Organizational Leadership',
                'Supply Chain Management Basics',
                'Business Analytics for Decisions',
                'Corporate Finance Essentials',
            ],
            'marketing' => [
                'Digital Marketing Fundamentals',
                'SEO & Content Strategy',
                'Social Media Marketing',
                'Copywriting that Converts',
                'Email Automation for Small Teams',
                'Google Analytics Foundations',
                'Brand Strategy & Positioning',
                'The Growth Marketing Playbook',
                'Paid Ads on Meta & Google',
                'Influencer Marketing Essentials',
            ],
        ];

        $levels = ['beginner', 'beginner', 'intermediate', 'intermediate', 'intermediate', 'advanced'];

        $published = [];

        foreach ($titles as $categorySlug => $categoryTitles) {
            $category = CourseCategory::where('slug', $categorySlug)->firstOrFail();

            foreach ($categoryTitles as $title) {
                $course = Course::create([
                    'instructor_id' => $this->instructorIds[array_rand($this->instructorIds)],
                    'category_id' => $category->id,
                    'title' => $title,
                    'slug' => Str::slug($title),
                    'short_description' => fake()->realText(110),
                    'description' => fake()->realText(420),
                    'level' => $levels[array_rand($levels)],
                    'language' => 'en',
                    'duration_minutes' => fake()->randomElement([180, 240, 300, 360, 420, 480, 540, 600, 720, 840, 960, 1080]),
                    'status' => CourseStatus::Published,
                    'price' => fake()->randomElement([null, null, null, 19.99, 39.99, 59.99, 79.99]),
                    'learning_objectives' => [
                        fake()->realText(70),
                        fake()->realText(70),
                        fake()->realText(70),
                    ],
                    'requirements' => fake()->randomElement([
                        [],
                        [fake()->realText(50)],
                        [fake()->realText(50), fake()->realText(50)],
                    ]),
                    'published_at' => now()->subDays(random_int(20, 370)),
                ]);

                $this->withCourseCover($course);
                $this->buildCourseContent($course, $categorySlug);

                $published[] = $course;
            }
        }

        foreach ($published as $i => $course) {
            if ($i < self::DRAFT_COUNT) {
                $course->update(['status' => CourseStatus::Draft, 'published_at' => null]);
            } elseif ($i < self::DRAFT_COUNT + self::ARCHIVED_COUNT) {
                $course->update(['status' => CourseStatus::Archived]);
            }
        }

        return $published;
    }

    private function buildCourseContent(Course $course, string $categorySlug): void
    {
        $sectionTitles = match ($categorySlug) {
            'web-development' => ['Getting Started', 'Core Concepts', 'Building Real Features', 'Testing & Debugging', 'Ship It'],
            'ui-ux-design' => ['Foundations', 'Research', 'Design & Prototype', 'Testing & Refining', 'Delivering'],
            'data-science' => ['Foundations', 'Data Wrangling', 'Modeling', 'Evaluation', 'Real-World Projects'],
            'business' => ['Foundations', 'Core Frameworks', 'Applying Your Skills', 'Case Studies', 'Your Next Steps'],
            default => ['Foundations', 'Strategy', 'Execution', 'Measurement', 'Growth'],
        };

        $sections = [];

        foreach (array_slice($sectionTitles, 0, random_int(4, 5)) as $index => $sectionTitle) {
            $section = CourseSection::create([
                'course_id' => $course->id,
                'title' => $sectionTitle,
                'sort_order' => ($index + 1) * 10,
            ]);

            $sections[] = $section;
        }

        foreach ($sections as $sectionIndex => $section) {
            $lessonCount = random_int(2, 3);

            for ($i = 1; $i <= $lessonCount; $i++) {
                $this->typeLesson($course, $section, ($sectionIndex * 10) + $i);
            }
        }
    }

    private function typeLesson(Course $course, CourseSection $section, int $order): Lesson
    {
        return match (random_int(1, 8)) {
            1 => $this->videoLesson($course, $section, $order),
            2 => $this->documentLesson($course, $section, $order),
            3 => $this->externalLesson($course, $section, $order),
            4 => $this->quizLesson($course, $section, $order),
            5 => $this->assignmentLesson($course, $section, $order),
            default => $this->textLesson($course, $section, $order),
        };
    }

    private function lessonTitle(): string
    {
        return ucfirst(fake()->realText(random_int(12, 36)));
    }

    private function textLesson(Course $course, CourseSection $section, int $order): Lesson
    {
        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => $this->lessonTitle(),
            'type' => LessonType::Text->value,
            'content' => fake()->paragraphs(3, true),
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function videoLesson(Course $course, CourseSection $section, int $order): Lesson
    {
        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => $this->lessonTitle(),
            'type' => LessonType::Video->value,
            'content' => null,
            'video_path' => 'videos/demo.mp4',
            'duration_seconds' => random_int(180, 1500),
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function documentLesson(Course $course, CourseSection $section, int $order): Lesson
    {
        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => $this->lessonTitle(),
            'type' => LessonType::Document->value,
            'content' => fake()->paragraphs(2, true),
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function externalLesson(Course $course, CourseSection $section, int $order): Lesson
    {
        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => $this->lessonTitle(),
            'type' => LessonType::ExternalLink->value,
            'external_url' => fake()->randomElement([
                'https://www.php.net/manual/en/',
                'https://developer.mozilla.org/en-US/docs/Web/',
                'https://laravel.com/docs',
                'https://www.python.org/doc/',
                'https://react.dev/learn',
            ]),
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function quizLesson(Course $course, CourseSection $section, int $order): Lesson
    {
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'title' => $this->lessonTitle(),
            'description' => fake()->realText(120),
            'instructions' => fake()->realText(100),
            'time_limit_minutes' => fake()->randomElement([null, 10, 15, 20, 30]),
            'passing_score' => 70.00,
            'attempts_allowed' => 3,
            'status' => 'active',
        ]);

        $questionCount = random_int(4, 6);

        for ($q = 1; $q <= $questionCount; $q++) {
            $question = QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'type' => $this->questionTypeValue(),
                'question_text' => rtrim(fake()->realText(40), '.').'?',
                'points' => 1.00,
                'sort_order' => $q,
            ]);

            $isTrueFalse = $question->type === 'true_false';
            $optionCount = $isTrueFalse ? 2 : 4;
            $correctIndex = random_int(0, $optionCount - 1);

            for ($o = 1; $o <= $optionCount; $o++) {
                QuizOption::create([
                    'quiz_question_id' => $question->id,
                    'option_text' => fake()->realText(random_int(10, 24)),
                    'is_correct' => $o === $correctIndex + 1,
                    'explanation' => $o === $correctIndex + 1 ? fake()->realText(80) : null,
                    'sort_order' => $o,
                ]);
            }
        }

        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'quiz_id' => $quiz->id,
            'title' => 'Module Quiz: '.$this->lessonTitle(),
            'type' => LessonType::Quiz->value,
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function questionTypeValue(): string
    {
        return fake()->randomElement(['multiple_choice', 'multiple_choice', 'multiple_choice', 'true_false']);
    }

    private function assignmentLesson(Course $course, CourseSection $section, int $order): Lesson
    {
        $assignment = Assignment::create([
            'course_id' => $course->id,
            'title' => $this->lessonTitle(),
            'description' => fake()->realText(120),
            'instructions' => fake()->realText(180),
            'due_at' => now()->addDays(random_int(7, 30)),
            'max_score' => 100.00,
            'allowed_file_types' => ['pdf', 'doc', 'docx', 'txt', 'png', 'jpg', 'zip'],
            'max_file_size_kb' => 10240,
            'status' => AssignmentStatus::Active,
        ]);

        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'assignment_id' => $assignment->id,
            'title' => 'Assignment: '.$this->lessonTitle(),
            'type' => LessonType::Assignment->value,
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    /** @param  array<int, Course>  $courses */
    private function createYearOfActivity(array $courses): void
    {
        $publishedCourses = collect($courses)
            ->filter(fn (Course $c): bool => $c->status->value === CourseStatus::Published->value)
            ->values();

        foreach ($this->studentIds as $studentId) {
            $courseCount = random_int(2, 10);
            $courseIds = (array) $publishedCourses
                ->pluck('id')
                ->shuffle()
                ->take($courseCount)
                ->all();

            foreach ($courseIds as $courseId) {
                $course = $publishedCourses->firstWhere('id', $courseId);

                if (! $course instanceof Course) {
                    continue;
                }

                $this->addCourseActivity($studentId, $course);
            }
        }
    }

    private function addCourseActivity(int $studentId, Course $course): void
    {
        $lessons = $course->lessons->sortBy(fn (Lesson $l): int => $l->sort_order)->values();

        $roll = random_int(1, 100);
        $status = match (true) {
            $roll <= 32 => EnrollmentStatus::Completed,
            $roll <= 90 => EnrollmentStatus::Active,
            default => EnrollmentStatus::Cancelled,
        };

        $progress = match ($status) {
            EnrollmentStatus::Completed => 100,
            EnrollmentStatus::Active => random_int(5, 95),
            EnrollmentStatus::Cancelled => random_int(1, 25),
        };

        $enrolledAt = now()->subDays(random_int(15, 365));

        $enrollment = Enrollment::create([
            'student_id' => $studentId,
            'course_id' => $course->id,
            'status' => $status->value,
            'progress_percent' => $progress,
            'enrolled_at' => $enrolledAt,
            'last_accessed_at' => now()->subDays(random_int(0, 21)),
        ]);

        $completedCount = (int) floor($lessons->count() * ($progress / 100));

        foreach ($lessons->take(max(0, $completedCount))->all() as $lesson) {
            $this->completeLesson($studentId, $course, $lesson, $enrolledAt);
        }

        if ($completedCount < $lessons->count() && $lessons->count() > 0) {
            $started = $lessons->get($completedCount);

            LessonProgress::create([
                'student_id' => $studentId,
                'course_id' => $course->id,
                'lesson_id' => $started->id,
                'progress_percent' => random_int(5, 40),
                'started_at' => $enrolledAt->copy()->addDays(random_int(1, 14)),
                'last_accessed_at' => now()->subDays(random_int(0, 14)),
            ]);
        }

        if ($status === EnrollmentStatus::Completed) {
            $completedAt = $enrolledAt->copy()->addDays(random_int(14, 140));
            $enrollment->update([
                'last_accessed_at' => $completedAt->copy()->addHours(random_int(1, 24)),
                'completed_at' => $completedAt,
            ]);

            $this->certificate($studentId, $course, $enrollment, $completedAt);
        }

        $this->quizActivity($studentId, $course, $enrolledAt, $status, $completedCount);
        $this->assignmentActivity($studentId, $course, $enrolledAt, $status, $completedCount);
    }

    private function completeLesson(int $studentId, Course $course, Lesson $lesson, Carbon $enrolledAt): void
    {
        $startedAt = $enrolledAt->copy()->addDays(random_int(1, 21));

        LessonProgress::updateOrCreate(
            ['student_id' => $studentId, 'lesson_id' => $lesson->id],
            [
                'course_id' => $course->id,
                'progress_percent' => 100,
                'started_at' => $startedAt,
                'completed_at' => $startedAt->copy()->addMinutes(random_int(3, 45)),
                'last_accessed_at' => $startedAt->copy()->addMinutes(random_int(3, 45)),
            ],
        );
    }

    private function certificate(int $studentId, Course $course, Enrollment $enrollment, Carbon $completedAt): void
    {
        Certificate::firstOrCreate(
            ['enrollment_id' => $enrollment->id],
            [
                'student_id' => $studentId,
                'course_id' => $course->id,
                'certificate_number' => 'LMS-'.str_pad((string) $this->certificateCounter++, 6, '0', STR_PAD_LEFT),
                'identifier' => Str::uuid()->toString(),
                'issued_at' => $completedAt->copy()->addDay(),
            ],
        );
    }

    private function quizActivity(
        int $studentId,
        Course $course,
        Carbon $enrolledAt,
        EnrollmentStatus $status,
        int $completedCount,
    ): void {
        if ($status === EnrollmentStatus::Cancelled) {
            return;
        }

        $quizLessons = $course->lessons
            ->filter(fn (Lesson $l): bool => $l->quiz_id !== null)
            ->sortBy(fn (Lesson $l): int => $l->sort_order)
            ->values();

        foreach ($quizLessons->take(max(0, $completedCount)) as $index => $lesson) {
            $quiz = Quiz::query()->find($lesson->quiz_id);

            if (! $quiz) {
                continue;
            }

            $questions = $quiz->questions()->with('options')->get();

            if ($questions->isEmpty()) {
                continue;
            }

            $passed = random_int(1, 10) <= 8;
            $attemptCount = $passed ? 1 : 2;

            for ($attemptIndex = 0; $attemptIndex < $attemptCount; $attemptIndex++) {
                $actuallyPassed = $passed || $attemptIndex === 1;
                $attempt = $this->quizAttempt($studentId, $quiz, $enrolledAt, $questions, $actuallyPassed);

                if ($actuallyPassed) {
                    break;
                }
            }
        }
    }

    private function quizAttempt(int $studentId, Quiz $quiz, Carbon $enrolledAt, $questions, bool $passed): QuizAttempt
    {
        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'status' => QuizAttemptStatus::Completed->value,
            'started_at' => $enrolledAt->copy()->addDays(random_int(2, 60)),
            'submitted_at' => $enrolledAt->copy()->addDays(random_int(2, 62))->addMinutes(random_int(3, 25)),
        ]);

        $total = 0.0;
        $possible = 0.0;

        foreach ($questions as $question) {
            $correctOption = $question->options->firstWhere('is_correct', true);
            $chosenCorrect = $passed || random_int(1, 100) <= 55;
            $earned = $chosenCorrect ? (float) $question->points : 0.0;
            $total += $earned;
            $possible += (float) $question->points;

            QuizAnswer::create([
                'quiz_attempt_id' => $attempt->id,
                'quiz_question_id' => $question->id,
                'answer' => $chosenCorrect && $correctOption ? (string) $correctOption->id : 'none',
                'is_correct' => $chosenCorrect,
                'points_earned' => $earned,
            ]);
        }

        $percentage = $possible > 0 ? round(($total / $possible) * 100, 2) : 0.0;

        $attempt->update([
            'score' => round($total, 2),
            'score_percentage' => $percentage,
            'passed' => $percentage >= (float) $quiz->passing_score,
        ]);

        Grade::updateOrCreate(
            ['source_type' => QuizAttempt::class, 'source_id' => $attempt->id],
            [
                'student_id' => $studentId,
                'course_id' => $quiz->course_id,
                'type' => 'quiz',
                'score' => round($total, 2),
                'max_score' => round($possible, 2),
                'percentage' => $percentage,
                'graded_at' => $attempt->submitted_at,
            ],
        );

        return $attempt;
    }

    private function assignmentActivity(
        int $studentId,
        Course $course,
        Carbon $enrolledAt,
        EnrollmentStatus $status,
        int $completedCount,
    ): void {
        if ($status === EnrollmentStatus::Cancelled) {
            return;
        }

        $assignmentLessons = $course->lessons
            ->filter(fn (Lesson $l): bool => $l->assignment_id !== null)
            ->sortBy(fn (Lesson $l): int => $l->sort_order)
            ->values();

        foreach ($assignmentLessons->take(max(0, $completedCount)) as $lesson) {
            $assignment = Assignment::query()->find($lesson->assignment_id);

            if (! $assignment) {
                continue;
            }

            $graded = $status === EnrollmentStatus::Completed || random_int(1, 100) <= 70;
            $submittedAt = $enrolledAt->copy()->addDays(random_int(5, 90));

            $submission = AssignmentSubmission::firstOrCreate(
                ['assignment_id' => $assignment->id, 'student_id' => $studentId],
                [
                    'content' => fake()->paragraphs(2, true),
                    'version' => 1,
                    'status' => $graded ? SubmissionStatus::Graded->value : SubmissionStatus::Submitted->value,
                    'submitted_at' => $submittedAt,
                ],
            );

            if (! $graded) {
                continue;
            }

            $grade = (float) random_int(72, 99);

            $submission->update([
                'grade' => $grade,
                'feedback' => fake()->realText(90),
                'graded_by' => $course->instructor_id,
                'graded_at' => $submittedAt->copy()->addDays(random_int(1, 8)),
                'status' => SubmissionStatus::Graded->value,
            ]);

            Grade::updateOrCreate(
                ['source_type' => AssignmentSubmission::class, 'source_id' => $submission->id],
                [
                    'student_id' => $studentId,
                    'course_id' => $course->id,
                    'type' => 'assignment',
                    'score' => $grade,
                    'max_score' => 100.00,
                    'percentage' => $grade,
                    'feedback' => $submission->feedback,
                    'graded_by' => $course->instructor_id,
                    'graded_at' => $submission->graded_at,
                ],
            );
        }
    }
}
