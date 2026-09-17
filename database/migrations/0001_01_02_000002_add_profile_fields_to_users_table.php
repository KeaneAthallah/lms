<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('headline')->nullable()->after('name');
            $table->text('bio')->nullable()->after('headline');
            $table->string('avatar_path')->nullable()->after('bio');
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['headline', 'bio', 'avatar_path', 'is_active']);
        });
    }
};
