<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets `quiz_answers` hold an answer that has been saved but not yet graded.
 *
 * Autosave writes to the same table as grading, so a row can exist mid-attempt
 * with no verdict on it. `is_correct` was already nullable for exactly that
 * reason; `points_earned` was not, and its `0.00` default meant there was no way
 * to tell a draft from a graded zero. Nullable is that distinction: an ungraded
 * row is null on both columns, and nothing downstream has to infer it.
 *
 * `answer` stays NOT NULL. A draft for a question the student cleared is the
 * empty string, which is what the graders already serialise, so there is nothing
 * to gain from a nullable value here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->decimal('points_earned', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Grading writes every answer it reads, but a student who abandons an
        // attempt mid-quiz leaves drafts behind, and NOT NULL would refuse to
        // restore without clearing them first.
        DB::table('quiz_answers')->whereNull('is_correct')->delete();

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->decimal('points_earned', 5, 2)->default(0.00)->change();
        });
    }
};
