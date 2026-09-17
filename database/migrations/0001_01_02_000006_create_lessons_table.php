<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('course_sections')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->nullable();
            $table->foreignId('assignment_id')->nullable();
            $table->string('title');
            $table->string('type')->default('text');
            $table->longText('content')->nullable();
            $table->string('video_path')->nullable();
            $table->string('video_url')->nullable();
            $table->string('external_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['section_id', 'sort_order']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
