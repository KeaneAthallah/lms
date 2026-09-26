<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unauthenticated liveness probe.
 *
 * Deliberately leaks nothing beyond whether the process can serve traffic and
 * reach its dependencies: no version, no host, no exception detail.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): bool => DB::select('select 1') !== false),
            'storage' => $this->check(fn (): bool => $this->storageIsUsable()),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * A reachable, writable store is healthy. An empty directory is not a fault,
     * so the local check asks about writability rather than contents.
     */
    private function storageIsUsable(): bool
    {
        $disk = Storage::disk(config('filesystems.default'));

        try {
            $root = $disk->path('');

            return is_dir($root) && is_writable($root);
        } catch (\Throwable) {
            // Not a local disk: reaching the adapter without error is the check.
            $disk->files('');

            return true;
        }
    }

    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (\Throwable) {
            return false;
        }
    }
}
