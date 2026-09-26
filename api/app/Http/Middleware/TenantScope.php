<?php

namespace Mordomus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Http\Responses\ErrorEnvelope;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\JwtVerifier;
use Mordomus\Support\Logging\LogContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige tenant ativo no JWT (claim `tid`) + membership ativo.
 * Falhas → 401 sem token/tenant · 403 sem membership.
 */
class TenantScope
{
    public function __construct(private readonly JwtVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $claims = $request->attributes->get('jwt_claims');

        // fallback: se o guard devolveu usuário cacheado sem revalidar o token
        if (! is_array($claims) && $request->bearerToken()) {
            try {
                $claims = $this->verifier->verify($request->bearerToken());
                $request->attributes->set('jwt_claims', $claims);
            } catch (\Throwable) {
                $claims = null;
            }
        }

        if (! is_array($claims)) {
            return $this->deny($request, 401, 'unauthenticated', 'Token inválido ou ausente.');
        }

        $tenantId = $claims['tid'] ?? null;

        if (! is_string($tenantId) || $tenantId === '') {
            return $this->deny($request, 403, 'tenant_required', 'Nenhuma residência ativa no token.');
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $this->deny($request, 401, 'unauthenticated', 'Token inválido ou ausente.');
        }

        // alimenta o TenantGlobalScope do Eloquent — popula antes de consultar
        // membership e limpa em finally para não vazar entre requisições do
        // mesmo worker FPM
        TenantContext::set($tenantId, $user->id, $claims);

        try {
            if (! $user->activeMembershipIn($tenantId)) {
                return $this->deny($request, 403, 'membership_required', 'Membership ativo não encontrado para a residência.');
            }

            app()->instance('mordomus.tenant_id', $tenantId);
            app()->instance('mordomus.claims', $claims);

            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }

    private function deny(Request $request, int $status, string $code, string $message): Response
    {
        Log::warning('tenant_scope.denied', [
            'status' => $status,
            'code' => $code,
            'user_id' => TenantContext::userId(),
            'tenant_id' => TenantContext::tenantId(),
            'path' => LogContext::path($request),
        ]);

        return ErrorEnvelope::make($request, $status, $code, $message);
    }
}
