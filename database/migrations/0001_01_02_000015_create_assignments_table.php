<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->decimal('max_score', 8, 2)->default(100.00);
            $table->json('allowed_file_types')->nullable();
            $table->unsignedInteger('max_file_size_kb')->default(10240);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
