<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: quiz blueprints, the quotas that shape a bank draw.
 *
 * A bank quiz otherwise draws a uniformly random sample, so a 20-question paper
 * can be 20 multiple-choice items with nothing else on it. The paper length is
 * still `draw_size`; a blueprint only constrains the mix, which keeps the two
 * settings independent instead of making the author restate the total as a sum
 * of quotas.
 *
 * Rules hang off the quiz rather than off the bank. Two quizzes drawing from the
 * same pool should be able to sit the same bank differently, and a blueprint is
 * part of how that quiz is administered rather than a property of the content.
 *
 * One row per type per quiz: the unique index is what stops a duplicated type
 * from quietly doubling a quota, and `question_type` is stored as the enum's
 * string value so the draw can filter on it directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_blueprint_rules', function (Blueprint $table) {
            $table->id();

            // `cascade` rather than `restrict`: unlike a bank, a blueprint is not
            // shared, so deleting a quiz cannot orphan anyone else's content.
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();

            $table->string('question_type');
            $table->unsignedInteger('question_count');

            $table->timestamps();

            $table->unique(['quiz_id', 'question_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_blueprint_rules');
    }
};
