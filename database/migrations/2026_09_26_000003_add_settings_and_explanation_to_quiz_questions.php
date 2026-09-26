<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 groundwork: per-question configuration and the question explanation
 * column the instructor controller has been writing to all along.
 *
 * `settings` is a JSON bag rather than a column per question type. Numeric
 * tolerance, multi-select partial credit, and fill-in-the-blank accepted
 * answers are all small, type-specific, and will keep arriving, so widening the
 * table per type would mean a migration each time. It is nullable: the original
 * three types need no configuration and should not have to carry an empty bag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            // The instructor quiz controller has always read and written
            // `explanation` on a question, but no such column existed, so the
            // attribute was silently discarded on write and always read back as
            // null. Per-option explanations lived on `quiz_options`; this is the
            // question-level equivalent and is what the review payload needs.
            $table->text('explanation')->nullable()->after('question_text');

            $table->json('settings')->nullable()->after('points');
        });

        Schema::table('quiz_questions', function (Blueprint $table) {
            // The question bank will filter by type, and pools will group by it.
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropIndex(['type']);
        });

        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropColumn(['explanation', 'settings']);
        });
    }
};
