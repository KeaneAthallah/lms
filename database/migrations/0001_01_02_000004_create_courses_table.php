<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('course_categories')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('short_description');
            $table->longText('description');
            $table->string('thumbnail_path')->nullable();
            $table->string('level')->default('beginner');
            $table->string('language')->default('en');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status')->default('draft');
            $table->decimal('price', 10, 2)->nullable();
            $table->json('learning_objectives')->nullable();
            $table->json('requirements')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
