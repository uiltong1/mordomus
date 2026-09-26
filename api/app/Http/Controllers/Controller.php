<?php

namespace Mordomus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mordomus\Http\Responses\ErrorEnvelope;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Identity\Models\Tenant;

abstract class Controller
{
    /**
     * @param  array<string, mixed>  $details
     */
    protected function error(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $details = [],
    ): JsonResponse {
        return ErrorEnvelope::make($request, $status, $code, $message, $details);
    }

    /** Tenant ativo definido pelo middleware `tenant`. */
    protected function activeTenantId(Request $request): ?string
    {
        return ActiveTenant::id($request);
    }

    /** Garante que o recurso pertence ao tenant ativo do token (blindagem multitenant). */
    protected function assertTenant(Tenant $tenant, Request $request): void
    {
        $active = $this->activeTenantId($request);

        if ($active === null || $tenant->id !== $active) {
            abort(403, 'tenant_mismatch');
        }
    }
}
