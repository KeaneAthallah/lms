<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Question versioning (Phase 2).
     *
     * A question an attempt has already been served cannot change in place: the
     * attempt review and grading are rebuilt from the question row, so rewriting
     * it would claim the student was asked something they never saw. Instead of
     * freezing such questions (the previous behaviour), editing one mints a new
     * row that becomes the owned "head", and the old head is detached.
     *
     * `replaced_by_id` points from a detached historical version at the version
     * that superseded it, so the lineage walks forward to the current head; the
     * head itself has null. Served attempts keep referencing the detached rows
     * by id, so history stays immutable while future papers use the new version.
     */
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->foreignId('replaced_by_id')->nullable()->after('id')->constrained('quiz_questions')->nullOnDelete();
            $table->unsignedInteger('version')->default(1)->after('replaced_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropColumn('version');
            $table->dropForeign(['replaced_by_id']);
            $table->dropColumn('replaced_by_id');
        });
    }
};
