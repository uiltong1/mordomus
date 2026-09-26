<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /health e GET /api/v1/{service}/health — critério de aceite T1.1.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $started = microtime(true);
        $database = 'up';

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (\Throwable $e) {
            $database = 'down';
        }

        $status = $database === 'down' ? 'degraded' : 'ok';

        return response()->json([
            'service' => config('app.name'),
            'status' => $status,
            'checks' => [
                'database' => [
                    'status' => $database,
                    'connection' => (string) config('database.default'),
                ],
                'cache' => (string) config('cache.default'),
                'queue' => (string) config('queue.default'),
            ],
            'tenant' => request()->header('X-Tenant-ID'),
            'request_id' => request()->header('X-Request-Id') ?? (string) Str::uuid(),
            'duration_ms' => round((microtime(true) - $started) * 1000, 1),
            'timestamp' => now()->setTimezone(config('app.timezone'))->toIso8601String(),
        ], $database === 'down' ? 503 : 200);
    }
}
