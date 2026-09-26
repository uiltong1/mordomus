<?php

declare(strict_types=1);

namespace Mordomus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\Health;
use OpenApi\Attributes as OA;

/**
 * Ponto de saúde nas três superfícies em que o serviço é exposto:
 * raiz do host, prefixo da API e namespace do módulo Identity.
 */
class HealthController
{
    #[OA\Get(
        path: '/health',
        operationId: 'webHealth',
        summary: 'Saúde do serviço (raiz do host)',
        tags: ['system'],
        responses: [
            new OA\Response(response: 200, description: 'Serviço saudável', content: new OA\JsonContent(ref: Health::class)),
            new OA\Response(response: 503, description: 'Banco de dados indisponível', content: new OA\JsonContent(ref: Health::class)),
        ],
    )]
    #[OA\Get(
        path: '/api/v1/health',
        operationId: 'apiHealth',
        summary: 'Saúde do serviço (prefixo da API)',
        tags: ['system'],
        responses: [
            new OA\Response(response: 200, description: 'Serviço saudável', content: new OA\JsonContent(ref: Health::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 503, description: 'Banco de dados indisponível', content: new OA\JsonContent(ref: Health::class)),
        ],
    )]
    #[OA\Get(
        path: '/api/v1/identity/health',
        operationId: 'identityHealth',
        summary: 'Saúde do serviço (módulo Identity)',
        tags: ['system'],
        responses: [
            new OA\Response(response: 200, description: 'Serviço saudável', content: new OA\JsonContent(ref: Health::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 503, description: 'Banco de dados indisponível', content: new OA\JsonContent(ref: Health::class)),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $started = microtime(true);
        $database = 'up';

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (\Throwable) {
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
            // o escopo de residência vem do backend (JWT + membership); o header
            // X-Tenant-ID vindo do cliente é descartado pelo gateway
            'tenant' => app()->bound('mordomus.tenant_id') ? app('mordomus.tenant_id') : null,
            'request_id' => request()->header('X-Request-Id') ?? (string) Str::uuid(),
            'duration_ms' => round((microtime(true) - $started) * 1000, 1),
            'timestamp' => now()->setTimezone(config('app.timezone'))->toIso8601String(),
        ], $database === 'down' ? 503 : 200);
    }
}
