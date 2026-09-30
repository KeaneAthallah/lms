<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LessonMaterialOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function course(): Course
    {
        return Course::factory()->for(User::factory()->instructor(), 'instructor')->create();
    }

    private function lesson(Course $course): Lesson
    {
        return Lesson::factory()
            ->for(CourseSection::factory()->for($course), 'section')
            ->for($course)
            ->create();
    }

    private function upload(Course $course, Lesson $lesson, string $filename = 'notes.pdf')
    {
        return $this->actingAs($course->instructor)
            ->post("/api/instructor/courses/{$course->slug}/lessons/{$lesson->id}/materials", [
                'file' => UploadedFile::fake()->create($filename, 4, 'application/pdf'),
            ]);
    }

    public function test_the_first_material_on_a_lesson_is_numbered_one(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lesson($course);

        $this->upload($course, $lesson)->assertCreated();

        // `max('sort_order')` is null on an empty lesson, so the cast to int is
        // load-bearing: without it the first material would be stored as 1 only
        // by accident of null coercion, and the sequence would start at 0.
        $this->assertDatabaseHas('lesson_materials', [
            'lesson_id' => $lesson->id,
            'sort_order' => 1,
        ]);
    }

    public function test_materials_are_appended_in_the_order_they_are_uploaded(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lesson($course);

        foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $filename) {
            $this->upload($course, $lesson, $filename)->assertCreated();
        }

        $this->assertSame(
            [1, 2, 3],
            $lesson->materials()->orderBy('id')->pluck('sort_order')->all(),
        );
    }

    /**
     * `max('sort_order') + 1` is a read-then-write, so the lesson row is locked
     * for the duration of the read and the insert that consumes it. Without the
     * lock, two uploads landing together both read the same maximum and both
     * insert it, leaving two materials with an identical `sort_order`.
     *
     * That collision is silent: `lesson_materials` has an index on
     * `(lesson_id, sort_order)` but no unique constraint, so nothing raises and
     * the listing simply breaks the tie arbitrarily.
     *
     * These two tests pin the parts of that fix which are observable here. The
     * test suite runs on SQLite, which has no row-level locking and whose
     * grammar drops the `for update` clause entirely, so the clause itself is
     * only asserted on MySQL -- see the second test for what that leaves
     * uncovered.
     */
    public function test_the_next_sort_order_is_read_while_a_transaction_the_controller_opened_is_open(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lesson($course);

        $ambient = DB::transactionLevel();
        $depthAtMaxRead = null;
        $depthAtInsert = null;

        DB::listen(function ($query) use (&$depthAtMaxRead, &$depthAtInsert): void {
            if (str_contains($query->sql, 'lesson_materials')) {
                if (str_contains($query->sql, 'max(')) {
                    $depthAtMaxRead = DB::transactionLevel();
                } elseif (str_starts_with(strtolower($query->sql), 'insert into')) {
                    $depthAtInsert = DB::transactionLevel();
                }
            }
        });

        $this->upload($course, $lesson)->assertCreated();

        $this->assertNotNull($depthAtMaxRead, 'The next sort order was never read.');
        $this->assertNotNull($depthAtInsert, 'The material insert was never issued.');

        // RefreshDatabase wraps each test in a transaction of its own, so the
        // meaningful comparison is against that ambient depth, not against zero.
        $this->assertGreaterThan($ambient, $depthAtMaxRead, 'sort_order was read outside any transaction the controller opened, so two uploads can both read the same maximum.');
        $this->assertGreaterThan($ambient, $depthAtInsert, 'The row was inserted outside any transaction the controller opened, so the read and the write are not serialized.');
    }

    public function test_the_lesson_is_locked_before_the_next_sort_order_is_read(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('SQLite has no row-level locking, so Laravel\'s grammar omits `for update` and there is nothing to assert.');
        }

        Storage::fake('local');

        $course = $this->course();
        $lesson = $this->lesson($course);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->upload($course, $lesson)->assertCreated();

        $lockAt = null;
        $readAt = null;

        foreach ($queries as $index => $sql) {
            if ($lockAt === null && str_contains($sql, '`lessons`') && str_contains($sql, 'for update')) {
                $lockAt = $index;
            }

            if ($readAt === null && str_contains($sql, 'lesson_materials') && str_contains($sql, 'max(')) {
                $readAt = $index;
            }
        }

        $this->assertNotNull($lockAt, 'The lesson row was never locked with `for update`, so the read-then-write is unprotected.');
        $this->assertNotNull($readAt, 'The next sort order was never read.');
        $this->assertLessThan($readAt, $lockAt, 'The lock must be taken before the maximum is read, or it protects nothing.');
    }

    public function test_each_lesson_keeps_its_own_material_sequence(): void
    {
        Storage::fake('local');

        $course = $this->course();
        $first = $this->lesson($course);
        $second = $this->lesson($course);

        $this->upload($course, $first)->assertCreated();
        $this->upload($course, $second)->assertCreated();
        $this->upload($course, $first)->assertCreated();

        // The next number is scoped to the lesson, not to the course: a material
        // on one lesson must not push the next upload on a sibling along.
        $this->assertSame([1, 2], $first->materials()->orderBy('id')->pluck('sort_order')->all());
        $this->assertSame([1], $second->materials()->orderBy('id')->pluck('sort_order')->all());
    }
}
