<?php

namespace Mordomus\Identity\Services;

use Firebase\JWT\JWT;
use Mordomus\Identity\Models\User;
use Illuminate\Support\Str;

/**
 * Emissão do access token de usuário (claims TECHSPEC §4.1: sub, tid, tenants, iss).
 * O refresh token é emitido/rotacionado por RefreshTokenService.
 */
class JwtIssuer
{
    /**
     * Montagem das claims (puro — coberto por teste unitário sem banco).
     *
     * @param  list<string>  $tenantIds
     * @return array<string, mixed>
     */
    public function claims(string $userId, ?string $activeTenantId, array $tenantIds, int $timestamp): array
    {
        $ttl = (int) config('jwt.ttl', 60);

        return [
            'iss' => (string) config('jwt.issuer', 'mordomus'),
            'sub' => $userId,
            'tid' => $activeTenantId,
            'tenants' => array_values($tenantIds),
            'iat' => $timestamp,
            'exp' => $timestamp + ($ttl * 60),
            'jti' => (string) Str::ulid(),
        ];
    }

    /**
     * @return array{token_type: string, access_token: string, expires_in: int, active_tenant: ?string}
     */
    public function issue(User $user, ?string $activeTenantId = null): array
    {
        $privateKey = config('jwt.private_key');
        if (! $privateKey) {
            throw new \RuntimeException('JWT_RSA_PRIVATE_KEY ausente — rode "make secrets" e suba via compose.');
        }

        $tenantIds = $user->memberships()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();

        if ($activeTenantId !== null && ! in_array($activeTenantId, $tenantIds, true)) {
            throw new \InvalidArgumentException('tenant ativo não pertence ao usuário');
        }

        $timestamp = now()->timestamp;
        $claims = $this->claims($user->id, $activeTenantId, $tenantIds, $timestamp);

        $accessToken = JWT::encode(
            $claims,
            $privateKey,
            (string) config('jwt.algorithm', 'RS256'),
            config('jwt.kid')
        );

        return [
            'token_type' => 'bearer',
            'access_token' => $accessToken,
            'expires_in' => $claims['exp'] - $timestamp,
            'active_tenant' => $activeTenantId,
        ];
    }
}
