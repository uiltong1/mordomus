<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Services\CapabilityResolver;

/**
 * Contratos comuns de resposta (formato de erro TECHSPEC §4.4).
 */
abstract class Controller
{
    protected function error(
        \Illuminate\Http\Request $request,
        int $status,
        string $code,
        string $message,
        array $details = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $status);
    }

    /** Tenant ativo definido pelo middleware `tenant`. */
    protected function activeTenantId(\Illuminate\Http\Request $request): ?string
    {
        return $request->attributes->get('jwt_claims')['tid'] ?? null;
    }

    /** Garante que o recurso pertence ao tenant ativo do token (blindagem multitenant). */
    protected function assertTenant(Tenant $tenant, \Illuminate\Http\Request $request): void
    {
        $active = $this->activeTenantId($request);

        if ($active === null || $tenant->id !== $active) {
            abort(403, 'tenant_mismatch');
        }
    }

    /** @return list<string> */
    protected function capabilitiesOf(Membership $membership): array
    {
        return app(CapabilityResolver::class)->keys($membership);
    }
}
