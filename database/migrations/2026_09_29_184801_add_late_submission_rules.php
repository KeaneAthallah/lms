<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedInteger('late_grace_minutes')->nullable()->after('available_until');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->boolean('submitted_late')->default(false)->after('submitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('late_grace_minutes');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('submitted_late');
        });
    }
};
