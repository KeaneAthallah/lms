<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: question banks, and the per-attempt question snapshot they require.
 *
 * A bank is a course-scoped pool of questions that a quiz can draw from instead
 * of owning. The two obvious designs both fail in ways that are hard to notice:
 *
 * - Resolving the drawn questions live, on every read, means a student who
 *   reopens an attempt is shown a different set than the one they answered, and
 *   a bank edit retroactively rewrites the review page of an already-graded
 *   attempt.
 * - Copying the questions into a new table per attempt would fork the answer key
 *   away from the bank, so the bank could no longer be edited at all and every
 *   type would need its own copy semantics.
 *
 * So the attempt records *which* questions it was served, in `quiz_attempt_questions`,
 * and every read of "the questions for this attempt" goes through that. Grading
 * then always sees exactly what the student saw, and a bank can still be edited
 * for future attempts.
 *
 * `quiz_questions.quiz_id` becomes nullable because a bank question belongs to
 * no quiz. Exactly one of `quiz_id` / `question_bank_id` is set; that invariant
 * is enforced in the model and the form request rather than by a database CHECK,
 * because SQLite cannot add a CHECK constraint to an existing table and this
 * schema has to migrate on both engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();

            // Scoped to a course rather than global: instructors manage their own
            // courses, and a shared global bank would need a cross-course
            // authorization story that does not exist anywhere else in the app.
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();

            // Nulled rather than cascaded if the author is deleted, so losing a
            // user account does not silently delete a course's question pool.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->index(['course_id', 'title']);
        });

        Schema::table('quizzes', function (Blueprint $table) {
            // `restrict` rather than `cascade`: deleting a bank that a quiz draws
            // from would leave the quiz permanently unservable. The instructor
            // controller turns this into a readable message.
            $table->foreignId('question_bank_id')->nullable()->after('course_id')->constrained('question_banks')->restrictOnDelete();
            $table->unsignedInteger('draw_size')->nullable()->after('question_bank_id');
        });

        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->foreignId('question_bank_id')->nullable()->after('quiz_id')->constrained('question_banks')->cascadeOnDelete();
            $table->index(['question_bank_id', 'sort_order']);
        });

        // Separate statement from the index above: on SQLite, altering a column
        // rebuilds the table, and mixing the rebuild with an index change makes
        // the generated DDL order driver-dependent.
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->unsignedBigInteger('quiz_id')->nullable()->change();
        });

        Schema::create('quiz_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();

            // `restrict` is the integrity backstop for the whole feature. The
            // instructor controller already refuses to edit or delete a question
            // a student has seen, but this also stops a bank-wide cascade from
            // quietly deleting a question out from under a graded attempt.
            $table->foreignId('quiz_question_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // One row per question per attempt; a draw must not repeat a question.
            $table->unique(['quiz_attempt_id', 'quiz_question_id']);
            $table->index(['quiz_attempt_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_questions');

        // A bank question has no quiz, so it cannot be moved back onto one. Drop
        // them before re-adding the NOT NULL the column used to carry.
        DB::table('quiz_questions')->whereNull('quiz_id')->delete();

        Schema::table('quiz_questions', function (Blueprint $table) {
            // The foreign key goes first: MySQL reuses the composite index above
            // as its backing key, so dropping the index while the key still
            // references it fails with "needed in a foreign key constraint".
            $table->dropForeign(['question_bank_id']);
            $table->dropIndex(['question_bank_id', 'sort_order']);
            $table->dropColumn('question_bank_id');
        });

        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->unsignedBigInteger('quiz_id')->nullable(false)->change();
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropForeign(['question_bank_id']);
            $table->dropColumn(['question_bank_id', 'draw_size']);
        });

        Schema::dropIfExists('question_banks');
    }
};
