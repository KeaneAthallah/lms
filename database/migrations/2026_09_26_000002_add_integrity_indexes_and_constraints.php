<?php

use App\Models\Certificate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the integrity and performance gaps found in the audit:
 *
 *  D1  lessons.quiz_id / lessons.assignment_id are read on every quiz and
 *      assignment lookup but were never indexed (full table scan).
 *  D2  certificates had no unique (student_id, course_id) constraint, so a race
 *      between two certificate requests could issue duplicates.
 *  D3  quiz_attempts was only indexed on (quiz_id, student_id); every
 *      cross-quiz student metric scanned the table.
 *  D4  course_prerequisites was only indexed on course_id; the reverse lookup
 *      (which courses depend on this one) scanned.
 *  D5  lesson_materials.disk defaulted to 'public' while the controller has
 *      always written to 'local' — a new row created without an explicit disk
 *      would land on the web-servable disk. The stored rows are left alone;
 *      only the default is corrected.
 *  D6  assignment_submissions had no index on student_id alone.
 *  D7  lesson_materials had no index covering the (lesson_id, sort_order)
 *      ordering every material listing performs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->index('quiz_id');
            $table->index('assignment_id');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->index('student_id');
        });

        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->index('student_id');
        });

        Schema::table('course_prerequisites', function (Blueprint $table) {
            $table->index('prerequisite_course_id');
        });

        Schema::table('lesson_materials', function (Blueprint $table) {
            $table->index(['lesson_id', 'sort_order']);
        });

        Schema::table('lesson_materials', function (Blueprint $table) {
            $table->string('disk')->default('local')->change();
        });

        $this->deduplicateCertificates();

        Schema::table('certificates', function (Blueprint $table) {
            $table->unique(['student_id', 'course_id']);
        });

        // MySQL keeps the original non-unique index once a unique one covers the
        // same columns, so the pair is redundant write overhead.
        $this->dropIndexIfPresent('certificates', 'certificates_student_id_course_id_index');
    }

    /**
     * Keep the earliest certificate per (student, course) so the unique index can
     * be added to an existing database. The index is what actually prevents the
     * race; this only makes the migration applicable.
     */
    private function deduplicateCertificates(): void
    {
        Certificate::query()
            ->orderBy('id')
            ->get(['id', 'student_id', 'course_id'])
            ->groupBy(fn ($certificate) => $certificate->student_id.'-'.$certificate->course_id)
            ->each(fn ($group) => $group->slice(1)->each(fn ($certificate) => $certificate->delete()));
    }

    public function down(): void
    {
        // MySQL satisfies a foreign key with the leftmost prefix of an index, so
        // dropping an index a constraint depends on is refused outright. Every
        // foreign key that one of this migration's indexes would back is
        // therefore lifted first, the indexes dropped, and the keys restored.
        $constraints = [
            'certificates.student_id' => 'users',
            'certificates.course_id' => 'courses',
            'lesson_materials.lesson_id' => 'lessons',
            'quiz_attempts.student_id' => 'users',
            'assignment_submissions.student_id' => 'users',
            'course_prerequisites.prerequisite_course_id' => 'courses',
            'lessons.quiz_id' => 'quizzes',
            'lessons.assignment_id' => 'assignments',
        ];

        foreach ($this->splitConstraints($constraints) as [$table, $column]) {
            if (! $this->hasForeignKeyOn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        }

        $this->dropIndexIfPresent('certificates', 'certificates_student_id_course_id_unique');
        $this->dropIndexIfPresent('lesson_materials', 'lesson_materials_lesson_id_sort_order_index');
        $this->dropIndexIfPresent('course_prerequisites', 'course_prerequisites_prerequisite_course_id_index');
        $this->dropIndexIfPresent('assignment_submissions', 'assignment_submissions_student_id_index');
        $this->dropIndexIfPresent('quiz_attempts', 'quiz_attempts_student_id_index');
        $this->dropIndexIfPresent('lessons', 'lessons_quiz_id_index');
        $this->dropIndexIfPresent('lessons', 'lessons_assignment_id_index');

        foreach ($constraints as $qualified => $referenced) {
            [$table, $column] = explode('.', $qualified);

            Schema::table($table, function (Blueprint $blueprint) use ($column, $referenced) {
                $blueprint->foreign($column)->references('id')->on($referenced)->cascadeOnDelete();
            });
        }

        Schema::table('lesson_materials', function (Blueprint $table) {
            $table->string('disk')->default('public')->change();
        });
    }

    /**
     * @param  array<string, string>  $constraints
     * @return array<int, array{0: string, 1: string}>
     */
    private function splitConstraints(array $constraints): array
    {
        $pairs = [];

        foreach (array_keys($constraints) as $qualified) {
            [$table, $column] = explode('.', $qualified);

            $pairs[] = [$table, $column];
        }

        return $pairs;
    }

    /**
     * A rollback can be interrupted part-way through, so each drop is checked
     * rather than assumed. Re-running a failed rollback must not fail again.
     */
    private function dropIndexIfPresent(string $table, string $name): void
    {
        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
    }

    private function hasForeignKeyOn(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (in_array($column, (array) ($foreignKey['columns'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }
};
