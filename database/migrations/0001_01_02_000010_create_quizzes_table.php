<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedInteger('time_limit_minutes')->nullable();
            $table->decimal('passing_score', 5, 2)->default(70.00);
            $table->unsignedInteger('attempts_allowed')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('course_id');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreign('quiz_id')->references('id')->on('quizzes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['quiz_id']);
        });

        Schema::dropIfExists('quizzes');
    }
};
