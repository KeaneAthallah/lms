<?php

namespace Database\Seeders;

use App\CourseStatus;
use App\LessonType;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\QuizQuestionType;
use Database\Seeders\Traits\GeneratesSeedImages;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    use GeneratesSeedImages;

    public function run(): void
    {
        $development = fn (string $n) => User::where('email', $n)->firstOrFail();
        $sarah = $development('sarah@example.com');
        $rachel = $development('rachel@example.com');
        $mark = $development('mark@example.com');

        $this->buildLaravelCourse($sarah);
        $this->buildDesignCourse($rachel);
        $this->buildDataScienceCourse($mark);
        $this->buildBusinessCourse($sarah);
    }

    private function buildLaravelCourse(User $instructor): void
    {
        $category = CourseCategory::where('slug', 'web-development')->firstOrFail();

        $course = Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Modern Web Development with Laravel',
            'slug' => 'modern-web-development-with-laravel',
            'short_description' => 'Master the Laravel framework from routing to queues and build real production applications.',
            'description' => "This course takes you from the fundamentals of Laravel to advanced topics like queues, caching, and API design. You will build a complete application step by step, learning clean architecture, testing, and deployment along the way. By the end you will be comfortable shipping Laravel applications to production with confidence.\n\nThe course mixes short video lessons, hands-on text tutorials, and quizzes to lock in what you learn.",
            'level' => 'intermediate',
            'language' => 'en',
            'duration_minutes' => 540,
            'status' => CourseStatus::Published,
            'price' => 99.99,
            'learning_objectives' => [
                'Build production-ready web applications with Laravel',
                'Design clean database schemas and Eloquent relationships',
                'Secure applications with authentication and authorization',
                'Write meaningful feature tests for your application',
            ],
            'requirements' => [
                'Basic PHP knowledge',
                'Familiarity with the command line',
                'Node.js installed for the frontend tooling',
            ],
            'published_at' => now()->subMonths(3),
        ]);

        $this->withCourseCover($course);

        $intro = $this->section($course, 'Getting Started', 10);
        $this->lesson($course, $intro, 'Welcome to the Course', LessonType::Video, 1, null, 'videos/welcome.mp4', 180);
        $this->lesson($course, $intro, 'Installing Laravel', LessonType::Text, 2, "Let's install Laravel and verify the tooling works.\n\n```bash\ncomposer create-project laravel/laravel lms\nphp artisan serve\n```\n\nOnce the welcome page loads, you are ready to continue.");
        $this->lesson($course, $intro, 'Project Setup Checklist', LessonType::Document, 3, 'Download the setup checklist and mark each item off as you go.');
        $this->externalLink($course, $intro, 'Laravel Documentation', 4, 'https://laravel.com/docs');

        $models = $this->section($course, 'Models & Migration', 20);
        $this->lesson($course, $models, 'Designing Your Database', LessonType::Text, 1, "Good schemas start with a clear domain model. Sketch your entities, their attributes, and relationships before writing a single migration.\n\nRemember to keep foreign keys indexed and to use constraints to protect data integrity.");
        $this->lesson($course, $models, 'Eloquent Relationships', LessonType::Video, 2, null, 'videos/relationships.mp4', 420);
        $this->quiz($course, $models, 'Models & Migration Quiz', 3, [
            [
                'question' => 'Which method defines a one-to-many relationship?',
                'type' => QuizQuestionType::MultipleChoice,
                'points' => 2,
                'options' => [
                    [$this->correctText('hasMany'), 'hasMany'],
                    ['belongsToMany', 'belongsToMany'],
                    ['hasOneThrough', 'hasOneThrough'],
                    ['morphOne', 'morphOne'],
                ],
            ],
            [
                'question' => 'A foreign key column should always be indexed.',
                'type' => QuizQuestionType::TrueFalse,
                'points' => 1,
                'options' => [
                    [$this->correctText('True'), 'True'],
                    ['False', 'False'],
                ],
            ],
        ]);

        $authSection = $this->section($course, 'Authentication & Authorization', 30);
        $this->lesson($course, $authSection, 'Session Authentication', LessonType::Text, 1, 'Sessions keep users signed in across requests. Laravel handles session storage and rotation for you—your job is to gate the routes that require it.');
        $this->lesson($course, $authSection, 'Policies and Gates', LessonType::Video, 2, null, 'videos/policies.mp4', 360);
        $this->assignment($course, $authSection, 'Build an Authorized Resource', 3, 'Create a resource with a policy protecting access, update, and delete operations. Write feature tests proving each gate.');

        $finish = $this->section($course, 'Wrapping Up', 40);
        $this->lesson($course, $finish, 'Course Wrap-up & Next Steps', LessonType::Text, 1, 'You have built the foundations of a real Laravel application. Keep shipping small features, write tests, and read the official documentation as your reference.');
    }

    private function buildDesignCourse(User $instructor): void
    {
        $category = CourseCategory::where('slug', 'ui-ux-design')->firstOrFail();

        $course = Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'UI & UX Design Fundamentals',
            'slug' => 'ui-ux-design-fundamentals',
            'short_description' => 'Learn the core principles of user interfaces and user experience design from scratch.',
            'description' => "Great design is intentional. In this course you will learn the visual and interaction principles behind modern interfaces: typography, spacing, color, layout, and the psychology of how users move through a product. You will practice by redesigning a real page.\n\nNo prior design experience is required.",
            'level' => 'beginner',
            'language' => 'en',
            'duration_minutes' => 320,
            'status' => CourseStatus::Published,
            'price' => 59.99,
            'learning_objectives' => [
                'Apply typography and spacing systems with confidence',
                'Build a simple color palette for accessible interfaces',
                'Explain core usability heuristics',
                'Design a responsive page layout',
            ],
            'requirements' => [
                'A modern browser',
                'A willingness to critique your own work',
            ],
            'published_at' => now()->subMonths(2),
        ]);

        $this->withCourseCover($course);

        $basics = $this->section($course, 'Design Principles', 10);
        $this->lesson($course, $basics, 'Why Design Matters', LessonType::Video, 1, null, 'videos/design-matters.mp4', 240);
        $this->lesson($course, $basics, 'Typography Basics', LessonType::Text, 2, 'Type is the voice of your interface. Choose a small, consistent type scale, keep line lengths readable, and let hierarchy do the work of guiding the eye.');
        $this->lesson($course, $basics, 'Color & Contrast', LessonType::Video, 3, null, 'videos/color.mp4', 300);

        $practice = $this->section($course, 'Building a Component', 20);
        $this->lesson($course, $practice, 'Spacing Systems', LessonType::Text, 1, "A spacing scale keeps layouts consistent. Start with a base unit and multiply: 4, 8, 12, 16, 24, 32, 48.\n\nConsistent spacing is one of the quickest wins for a polished look.");
        $this->lesson($course, $practice, 'Component Design Project', LessonType::Document, 2, 'A worksheet to design a card component with a defined type scale and spacing grid.');
        $this->quiz($course, $practice, 'Design Fundamentals Quiz', 3, [
            [
                'question' => 'What is the main purpose of a type scale?',
                'type' => QuizQuestionType::MultipleChoice,
                'points' => 1,
                'options' => [
                    [$this->correctText('To maintain visual hierarchy and consistency'), 'To maintain visual hierarchy and consistency'],
                    ['To use every font weight available', 'To use every font weight available'],
                    ['To make pages load faster', 'To make pages load faster'],
                    ['To replace images', 'To replace images'],
                ],
            ],
        ]);
        $this->assignment($course, $practice, 'Submit Your Card Component', 4, 'Design a card component following the guidance from the module and attach your export or sketch.');

        $finish = $this->section($course, 'Polish', 30);
        $this->lesson($course, $finish, 'Accessibility Audit', LessonType::Text, 1, 'Run through contrast checks, keyboard navigation, and clear labels. Accessibility is a feature, not an afterthought.');
    }

    private function buildDataScienceCourse(User $instructor): void
    {
        $category = CourseCategory::where('slug', 'data-science')->firstOrFail();

        $course = Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Data Science with Python',
            'slug' => 'data-science-with-python',
            'short_description' => 'Go from raw data to clear insights with Pandas, visualization, and machine-learning fundamentals.',
            'description' => 'This advanced course covers the full data workflow: exploration, cleaning, analysis, visualization, and predictive modeling with scikit-learn. You will work through a realistic dataset and finish with a working model and a written analysis.',
            'level' => 'advanced',
            'language' => 'en',
            'duration_minutes' => 720,
            'status' => CourseStatus::Published,
            'price' => 129.99,
            'learning_objectives' => [
                'Explore and clean messy datasets with Pandas',
                'Build informative visualizations with Matplotlib',
                'Train and evaluate classification models',
                'Communicate findings clearly',
            ],
            'requirements' => [
                'Python basics',
                'Comfort with Jupyter Notebooks',
            ],
            'published_at' => now()->subMonths(5),
        ]);

        $this->withCourseCover($course);

        $explore = $this->section($course, 'Exploratory Data Analysis', 10);
        $this->lesson($course, $explore, 'Your First Notebook', LessonType::Video, 1, null, 'videos/notebook.mp4', 360);
        $this->lesson($course, $explore, 'Describing Datasets', LessonType::Text, 2, 'Descriptive statistics summarise a dataset at a glance—but the summary is never the whole story. Always pair statistics with visual checks.');

        $modeling = $this->section($course, 'Modeling', 20);
        $this->lesson($course, $modeling, 'Preprocessing with Pandas', LessonType::Video, 1, null, 'videos/preprocessing.mp4', 420);
        $this->lesson($course, $modeling, 'Classification Basics', LessonType::Text, 2, 'Classification assigns labels to observations. Start with a simple baseline model, measure it honestly, then iterate.');
        $this->lesson($course, $modeling, 'Model Validation', LessonType::Document, 3, 'A practical guide on train/test splits and cross-validation.');
        $this->quiz($course, $modeling, 'Data Science Quiz', 4, [
            [
                'question' => 'Why do we split data into training and test sets?',
                'type' => QuizQuestionType::MultipleChoice,
                'points' => 1,
                'options' => [
                    [$this->correctText('To evaluate performance on unseen data'), 'To evaluate performance on unseen data'],
                    ['To make training slower', 'To make training slower'],
                    ['To increase the dataset size', 'To increase the dataset size'],
                    ['To remove missing values automatically', 'To remove missing values automatically'],
                ],
            ],
            [
                'question' => 'A high score on the training set guarantees good real-world performance.',
                'type' => QuizQuestionType::TrueFalse,
                'points' => 1,
                'options' => [
                    ['True', 'True'],
                    [$this->correctText('False'), 'False'],
                ],
            ],
        ]);

        $communicate = $this->section($course, 'Communicating Results', 30);
        $this->lesson($course, $communicate, 'Data Storytelling', LessonType::Text, 1, 'Good analysis is only useful if others understand it. Lead with the decision, then the evidence, then the caveats.');
        $this->assignment($course, $communicate, 'Write Your Findings', 2, 'Write a short report (max 2 pages) summarising the insights from your analysis and one recommendation.');
    }

    private function buildBusinessCourse(User $instructor): void
    {
        $category = CourseCategory::where('slug', 'business')->firstOrFail();

        $course = Course::create([
            'instructor_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Leadership Essentials',
            'slug' => 'leadership-essentials',
            'short_description' => 'Practical leadership skills for new and aspiring managers.',
            'description' => 'Leadership is a set of learnable skills. This compact course covers feedback, delegation, one-on-ones, and building trust on a team.',
            'level' => 'beginner',
            'language' => 'en',
            'duration_minutes' => 210,
            'status' => CourseStatus::Published,
            'price' => null,
            'learning_objectives' => [
                'Run effective one-on-one meetings',
                'Give actionable, kind feedback',
                'Delegate without micromanaging',
            ],
            'requirements' => [],
            'published_at' => now()->subWeeks(2),
        ]);

        $this->withCourseCover($course);

        $trust = $this->section($course, 'Building Trust', 10);
        $this->lesson($course, $trust, 'What Leaders Actually Do', LessonType::Text, 1, 'Leaders create the conditions for others to do their best work. That starts with clarity, honesty, and follow-through.');
        $this->lesson($course, $trust, 'Giving Effective Feedback', LessonType::Video, 2, null, 'videos/feedback.mp4', 300);
        $this->lesson($course, $trust, 'One-on-One Meetings', LessonType::Text, 3, 'Weekly one-on-ones are the heartbeat of a healthy team. Keep them a safe space and spend most of the time listening.');
        $this->quiz($course, $trust, 'Leadership Check', 4, [
            [
                'question' => 'Feedback is most effective when it is...',
                'type' => QuizQuestionType::MultipleChoice,
                'points' => 1,
                'options' => [
                    [$this->correctText('Specific, timely, and kind'), 'Specific, timely, and kind'],
                    ['Delivered only in annual reviews', 'Delivered only in annual reviews'],
                    ['Vague to avoid conflict', 'Vague to avoid conflict'],
                    ['Always written down', 'Always written down'],
                ],
            ],
            [
                'question' => 'Delegation means letting go of control over both the task and its standard of quality.',
                'type' => QuizQuestionType::TrueFalse,
                'points' => 1,
                'options' => [
                    ['True', 'True'],
                    [$this->correctText('False'), 'False'],
                ],
            ],
        ]);

        $finish = $this->section($course, 'Applying It', 20);
        $this->assignment($course, $finish, 'Write Your Leadership Plan', 1, 'Write a short plan for the next 30 days: your team goals, one feedback conversation you will start, and how you will run one-on-ones.');
    }

    private function section(Course $course, string $title, int $order): CourseSection
    {
        return CourseSection::create([
            'course_id' => $course->id,
            'title' => $title,
            'sort_order' => $order,
        ]);
    }

    private function lesson(
        Course $course,
        CourseSection $section,
        string $title,
        LessonType $type,
        int $order,
        ?string $content = null,
        ?string $videoPath = null,
        ?int $durationSeconds = null,
    ): Lesson {
        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => $title,
            'type' => $type->value,
            'content' => $content,
            'video_path' => $videoPath,
            'duration_seconds' => $durationSeconds,
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function externalLink(Course $course, CourseSection $section, string $title, int $order, string $url): Lesson
    {
        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => $title,
            'type' => LessonType::ExternalLink->value,
            'external_url' => $url,
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function quiz(Course $course, CourseSection $section, string $title, int $order, array $questionsData): Lesson
    {
        $quiz = Quiz::create([
            'course_id' => $course->id,
            'title' => $title,
            'description' => "A quick check on the module you just completed. You need 70% to pass. You'll see your result and explanations right away.",
            'instructions' => 'Read each question carefully and select the best answer. Short quizzes like this one have no time limit.',
            'time_limit_minutes' => null,
            'passing_score' => 70.00,
            'attempts_allowed' => 3,
            'status' => 'active',
        ]);

        foreach ($questionsData as $index => $qData) {
            $question = QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'type' => $qData['type']->value,
                'question_text' => $qData['question'],
                'points' => $qData['points'],
                'sort_order' => $index + 1,
            ]);

            foreach ($qData['options'] as $optionIndex => [$rawFlag, $text]) {
                $isCorrect = $rawFlag === true;

                QuizOption::create([
                    'quiz_question_id' => $question->id,
                    'option_text' => $text,
                    'is_correct' => $isCorrect,
                    'explanation' => $isCorrect ? 'This is the correct answer.' : null,
                    'sort_order' => $optionIndex + 1,
                ]);
            }
        }

        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'quiz_id' => $quiz->id,
            'title' => $title,
            'type' => LessonType::Quiz->value,
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function assignment(Course $course, CourseSection $section, string $title, int $order, string $instructions): Lesson
    {
        $assignment = Assignment::create([
            'course_id' => $course->id,
            'title' => $title,
            'description' => 'Complete this assignment and submit your work for feedback.',
            'instructions' => $instructions,
            'due_at' => now()->addWeeks(2),
            'max_score' => 100.00,
            'allowed_file_types' => ['pdf', 'doc', 'docx', 'txt', 'png', 'jpg', 'zip'],
            'max_file_size_kb' => 10240,
            'status' => 'active',
        ]);

        return Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'assignment_id' => $assignment->id,
            'title' => $title,
            'type' => LessonType::Assignment->value,
            'is_published' => true,
            'sort_order' => $order,
        ]);
    }

    private function correctText(string $text): bool
    {
        return true;
    }
}
