<?php

use App\Models\Lesson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Lesson videos were written to the `public` disk, which `public/storage` symlinks
 * to, so every uploaded video was world-readable with no authentication and no
 * revocation. This records where each lesson's video actually lives and moves the
 * existing files onto the private `local` disk.
 */
return new class extends Migration
{
    private const PRIVATE_DISK = 'local';

    private const LEGACY_DISK = 'public';

    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('video_disk')->nullable()->after('video_path');
        });

        Lesson::query()
            ->whereNotNull('video_path')
            ->where('video_path', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($lessons): void {
                foreach ($lessons as $lesson) {
                    $legacyPath = $lesson->video_path;

                    $legacy = Storage::disk(self::LEGACY_DISK);
                    $private = Storage::disk(self::PRIVATE_DISK);

                    if ($lesson->video_disk === self::PRIVATE_DISK) {
                        continue;
                    }

                    // Already moved (or never on the legacy disk) — just record the location.
                    if (! $legacy->exists($legacyPath)) {
                        $lesson->forceFill(['video_disk' => self::PRIVATE_DISK])->save();

                        continue;
                    }

                    $moved = true;

                    if (! $private->exists($legacyPath)) {
                        $moved = $legacy->move($legacyPath, $legacyPath);
                    } else {
                        $legacy->delete($legacyPath);
                    }

                    // A file we could not relocate must keep pointing at the disk that has it.
                    $lesson->forceFill([
                        'video_disk' => $moved ? self::PRIVATE_DISK : self::LEGACY_DISK,
                    ])->save();
                }
            });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('video_disk');
        });
    }
};
