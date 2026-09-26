<?php

namespace Mordomus\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige tenant ativo no JWT (claim `tid`) + membership ativo.
 * Falhas → 401 sem token/tenant · 403 sem membership (TECHSPEC §4.4).
 */
class EnsureActiveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $claims = $request->attributes->get('jwt_claims');

        // fallback: se o guard devolveu usuário cacheado sem revalidar o token
        if (! is_array($claims) && $request->bearerToken()) {
            try {
                $claims = app(\Mordomus\Identity\Services\JwtVerifier::class)
                    ->verify($request->bearerToken());
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

        if (! $user instanceof \Mordomus\Identity\Models\User) {
            return $this->deny($request, 401, 'unauthenticated', 'Token inválido ou ausente.');
        }

        if (! $user->activeMembershipIn($tenantId)) {
            return $this->deny($request, 403, 'membership_required', 'Membership ativo não encontrado para a residência.');
        }

        app()->instance('mordomus.tenant_id', $tenantId);
        app()->instance('mordomus.claims', $claims);

        return $next($request);
    }

    private function deny(Request $request, int $status, string $code, string $message): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => [],
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $status);
    }
}
