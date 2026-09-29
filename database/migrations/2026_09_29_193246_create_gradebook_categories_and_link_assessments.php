<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gradebook categories and the link between assessments and them.
 *
 * A category is a course-scoped bucket of assessments that shares a weight. The
 * earlier gradebook computed a course percentage as the simple mean of every
 * graded assessment; a category marks several assessments as one weighted
 * bucket, so a course percentage becomes the weighted mean of the buckets the
 * student has a grade in. Categories are a rubric for the gradebook only - a
 * quiz or assignment may sit in none, and the plain mean is then exactly what
 * the gradebook computed before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gradebook_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Categories load in order for the whole course, once, for the
            // gradebook's column grouping and the builder's settings modal.
            $table->index(['course_id', 'sort_order']);
        });

        // A category is optional and outlives an assessment it no longer holds:
        // deleting an assessment keeps the bucket, deleting the bucket just
        // sends its assessments back to "uncategorized".
        Schema::table('quizzes', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('course_id')
                ->constrained('gradebook_categories')->nullOnDelete();
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('course_id')
                ->constrained('gradebook_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('gradebook_categories');
    }
};
