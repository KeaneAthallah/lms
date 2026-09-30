<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Notifications\CourseCompleted;
use App\Notifications\EnrollmentConfirmed;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the notifications are actually *deferred* work rather than work that
 * merely happens to be labelled `ShouldQueue`.
 *
 * The suite runs with `QUEUE_CONNECTION=sync`, where a queued notification is
 * executed inline before `notify()` returns. That makes the rest of the suite
 * blind to the three properties that make queueing safe here:
 *
 *  1. a job row is written instead of the mail being sent inside the request;
 *  2. the row is written only once the surrounding transaction commits, so a
 *     rolled-back write cannot leave a mail describing state that never existed;
 *  3. the retry policy reaches the job, and stays inside the reservation window.
 *
 * All three need real commits, which is why this class uses `DatabaseMigrations`
 * instead of `RefreshDatabase` -- the refresh trait wraps every test in a
 * transaction that never commits, so `afterCommit()` callbacks would never fire
 * and every assertion here would pass vacuously.
 */
class NotificationQueueingTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Number of jobs Laravel writes for one `notify()` call on a notification
     * with `['database', 'mail']`.
     *
     * `NotificationSender::queueNotification()` iterates `via()` and dispatches
     * one `SendQueuedNotifications` per channel, so this is 2, not 1. The two
     * jobs share a notification id, which is what makes the database and mail
     * deliveries independently retryable.
     */
    private const JOBS_PER_NOTIFICATION = 2;

    /**
     * Retry spacing, in seconds, documented for the mail retries. It has to fit
     * inside the database driver's reservation window or a second worker picks
     * the job up mid-backoff and the notification is delivered twice.
     */
    private const RECOMMENDED_WORKER_BACKOFF = 30;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    protected function tearDown(): void
    {
        // `config()` changes leak between tests, and the rest of the suite needs
        // the sync connection to stay synchronous.
        config(['queue.default' => 'sync']);

        parent::tearDown();
    }

    private function jobCount(): int
    {
        return DB::table('jobs')->count();
    }

    /**
     * Rebuild the queued job objects from the stored payloads.
     *
     * @return array<int, SendQueuedNotifications>
     */
    private function queuedJobs(): array
    {
        return DB::table('jobs')->pluck('payload')
            ->map(function (string $payload) {
                $decoded = json_decode($payload, true);

                return unserialize($decoded['data']['command']);
            })
            ->all();
    }

    public function test_enrolling_writes_job_rows_instead_of_sending_mail(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        (new EnrollmentService)->enroll($student, $course);

        // Two notifications -- confirmation to the student, notice to the
        // instructor -- times two channels each.
        $this->assertSame(2 * self::JOBS_PER_NOTIFICATION, $this->jobCount());

        foreach ($this->queuedJobs() as $job) {
            $this->assertInstanceOf(
                SendQueuedNotifications::class,
                $job
            );
            $this->assertCount(1, $job->channels ?? []);
        }
    }

    public function test_the_two_channels_of_one_notification_share_a_notification_id(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        (new EnrollmentService)->enroll($student, $course);

        $ids = array_map(
            fn ($job) => $job->notification->id,
            $this->queuedJobs()
        );

        // Two notifications, two jobs each, sharing one id per notification.
        $this->assertCount(2 * self::JOBS_PER_NOTIFICATION, $ids);
        $this->assertCount(2, array_unique($ids));

        foreach (array_count_values($ids) as $occurrences) {
            $this->assertSame(self::JOBS_PER_NOTIFICATION, $occurrences);
        }
    }

    public function test_a_queued_notification_carries_its_attempt_limit(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        (new EnrollmentService)->enroll($student, $course);

        foreach ($this->queuedJobs() as $job) {
            // `tries` is copied off the notification by the
            // `SendQueuedNotifications` constructor, so it survives the trip
            // through the payload and the worker actually honours it.
            $this->assertSame(3, $job->tries);
        }
    }

    public function test_no_notification_declares_a_backoff_property(): void
    {
        $offenders = [];

        foreach (glob(app_path('Notifications/*.php')) as $path) {
            $reflection = new \ReflectionClass('App\\Notifications\\'.basename($path, '.php'));

            if ($reflection->isAbstract()) {
                continue;
            }

            if ($reflection->hasProperty('backoff')) {
                $offenders[] = $reflection->getShortName();
            }
        }

        // `SendQueuedNotifications` has no `$backoff` property and no
        // `backoff()` method, so `Worker::calculateBackoff()` ignores whatever
        // the notification declares and uses the worker's `--backoff` instead. A
        // property here looks correct, reads as an intentional policy, and does
        // nothing -- so it is treated as a bug rather than a style preference.
        $this->assertSame(
            [],
            $offenders,
            'These notifications declare $backoff, which the queue worker ignores: '.implode(', ', $offenders)
        );
    }

    public function test_the_recommended_worker_backoff_fits_inside_the_reservation_window(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        // Spacing documented for the mail retries in
        // `RetriesTransientMailFailures`. A --backoff at or above retry_after
        // double-delivers, because the reservation lapses while the previous
        // attempt is still sleeping and a second worker picks the job up.
        $this->assertLessThan(
            $retryAfter,
            self::RECOMMENDED_WORKER_BACKOFF,
            'A --backoff of '.self::RECOMMENDED_WORKER_BACKOFF."s does not fit a retry_after of {$retryAfter}s. "
                .'Lower the backoff or raise DB_QUEUE_RETRY_AFTER.'
        );
    }

    public function test_a_notification_dispatched_inside_a_transaction_is_deferred_until_it_commits(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        (new EnrollmentService)->enroll($student, $course);

        $before = $this->jobCount();

        DB::beginTransaction();

        $student->notify((new EnrollmentConfirmed($course))->afterCommit());

        // Still nothing: the transaction has not committed, so the enqueue is
        // held back rather than written.
        $this->assertSame($before, $this->jobCount());

        DB::commit();

        $this->assertSame($before + self::JOBS_PER_NOTIFICATION, $this->jobCount());
    }

    public function test_a_notification_is_discarded_when_its_transaction_rolls_back(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        (new EnrollmentService)->enroll($student, $course);

        $before = $this->jobCount();

        try {
            DB::transaction(function () use ($course, $student): void {
                $student->notify((new EnrollmentConfirmed($course))->afterCommit());

                throw new RuntimeException('Simulated failure after the notify call.');
            });
        } catch (RuntimeException) {
            // Expected control flow: the transaction failed on purpose.
        }

        // The enrollment was never durably written, so a "you are enrolled" mail
        // must not be sitting in the queue for a worker to deliver.
        $this->assertSame($before, $this->jobCount());
    }

    public function test_a_completion_inside_a_rolled_back_transaction_queues_nothing(): void
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'is_published' => true,
        ]);
        $student = User::factory()->student()->create();

        (new EnrollmentService)->enroll($student, $course);

        $before = $this->jobCount();

        try {
            DB::transaction(function () use ($course, $student): void {
                $student->notify((new CourseCompleted($course))->afterCommit());

                throw new RuntimeException('Simulated failure after the notify call.');
            });
        } catch (RuntimeException) {
            // Expected control flow: see above.
        }

        $this->assertSame($before, $this->jobCount());
        $this->assertDatabaseMissing('lesson_progress', [
            'student_id' => $student->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function test_a_freshly_queued_job_is_neither_reserved_nor_attempted(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();

        (new EnrollmentService)->enroll($student, $course);

        // `attempts = 0` with a null reservation is what untouched work looks
        // like. A row that is already reserved would mean something ran a
        // worker in-process, and the "asynchronous" claim would be false.
        $this->assertSame(
            [0, 0, 0, 0],
            DB::table('jobs')->orderBy('id')->pluck('attempts')->all()
        );

        $this->assertSame(
            [null, null, null, null],
            DB::table('jobs')->orderBy('id')->pluck('reserved_at')->all()
        );
    }
}
