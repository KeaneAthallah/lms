<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Support\CourseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a lesson video from private storage.
 *
 * Lesson videos used to be written to the `public` disk and returned as
 * `asset('storage/...')` URLs, which made every uploaded video world-readable
 * with no authentication and no revocation. This route re-establishes the
 * authorization boundary and supports byte ranges so players can seek.
 */
class LessonVideoController extends Controller
{
    public function __construct(protected CourseAccess $access) {}

    public function show(Request $request, Lesson $lesson): StreamedResponse
    {
        $user = $request->user();

        // 404 rather than 403: an unauthorised caller learns nothing, not even
        // that the lesson exists.
        abort_unless($user !== null && $this->access->canViewLessonContent($user, $lesson), 404);

        abort_unless($lesson->video_path, 404);

        $disk = Storage::disk($lesson->video_disk ?: 'local');

        abort_unless($disk->exists($lesson->video_path), 404);

        $size = (int) $disk->size($lesson->video_path);

        if ($size === 0) {
            abort(404);
        }

        $range = $this->requestedRange($request->header('Range'), $size);

        $partial = $range !== null;

        [$start, $end] = $partial ? $range : [0, $size - 1];

        $stream = $disk->readStream($lesson->video_path);

        if ($stream === false) {
            abort(404);
        }

        if ($start > 0) {
            if (fseek($stream, $start) !== 0) {
                // Not seekable (some remote disks): fall back to the full body.
                fclose($stream);
                $stream = $disk->readStream($lesson->video_path);
                $start = 0;
                $end = $size - 1;
                $partial = false;
            }
        }

        $length = $end - $start + 1;

        $response = new StreamedResponse(function () use ($stream, $length): void {
            $remaining = $length;

            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(1024 * 256, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;

                $remaining -= strlen($chunk);
            }

            fclose($stream);
        }, $partial ? 206 : 200, [
            'Content-Type' => $this->contentType($disk->mimeType($lesson->video_path)),
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $length,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($partial) {
            $response->headers->set('Content-Range', "bytes {$start}-{$end}/{$size}");
        }

        return $response;
    }

    /**
     * Stored videos are instructor uploads, so anything that is not a recognised
     * video type is served as an opaque download rather than being handed to a
     * player as if it were playable.
     */
    private function contentType(?string $mimeType): string
    {
        $mimeType = strtolower((string) $mimeType);

        return str_starts_with($mimeType, 'video/') ? $mimeType : 'application/octet-stream';
    }

    /**
     * Parse a single-range `Range: bytes=` header.
     *
     * @return array{0: int, 1: int}|null
     */
    private function requestedRange(?string $header, int $size): ?array
    {
        if ($header === null || ! str_starts_with($header, 'bytes=')) {
            return null;
        }

        $spec = substr($header, 6);

        // Multi-range requests are not worth the complexity; serve the whole file.
        if (str_contains($spec, ',')) {
            return null;
        }

        $parts = explode('-', $spec, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$rawStart, $rawEnd] = $parts;

        if ($rawStart === '') {
            // Suffix range: the last N bytes.
            if (! ctype_digit($rawEnd)) {
                return null;
            }

            $suffix = (int) $rawEnd;

            if ($suffix <= 0) {
                return null;
            }

            return [max(0, $size - $suffix), $size - 1];
        }

        if (! ctype_digit($rawStart)) {
            return null;
        }

        $start = (int) $rawStart;

        if ($start >= $size) {
            return null;
        }

        $end = $rawEnd === '' || ! ctype_digit($rawEnd) ? $size - 1 : min((int) $rawEnd, $size - 1);

        return $end < $start ? null : [$start, $end];
    }
}
