<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An append-only log of instructor decisions about a grade. Each row is a
        // complete snapshot of the adjustment in force after that action, not a
        // delta, so the current state is the newest row for a grade and the
        // history reads by pairing consecutive rows. The recorded ledger row in
        // `grades` is never touched: it stays what the grader produced, and a
        // later regrade cannot silently discard an instructor's decision.
        Schema::create('grade_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained('grades')->cascadeOnDelete();
            // Denormalized off the grade so the course-wide trail is one query
            // rather than a join back to `grades`; GradeAdjustmentService always
            // copies both from the grade it is adjusting.
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // override | clear_override | drop | restore | annotate
            $table->boolean('dropped')->default(false);
            // The override, all three or none: a score with no max_score or
            // percentage could not be averaged, and a percentage that
            // disagreed with the score could not be explained to a student.
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at');
            $table->timestamps();

            // The course-wide trail, newest first; the per-grade chain, ordered.
            $table->index(['course_id', 'adjusted_at']);
            $table->index(['grade_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_adjustments');
    }
};
