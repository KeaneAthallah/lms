<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonMaterial;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaterialController extends Controller
{
    public function download(Lesson $lesson, LessonMaterial $material): StreamedResponse
    {
        $this->authorize('view', $material);

        abort_unless((int) $material->lesson_id === (int) $lesson->id, 404);

        $disk = Storage::disk($material->disk ?? 'local');

        abort_unless($disk->exists($material->path), 404);

        $headers = [
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($material->is_downloadable) {
            return $disk->download($material->path, $material->filename, $headers);
        }

        return $disk->response($material->path, $material->filename, $headers);
    }
}
