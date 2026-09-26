<?php

namespace Mordomus\Identity\Services;

use Mordomus\Identity\Models\User;

/**
 * Embala access token + refresh token no envelope de sessão devolvido
 * pela API — o mesmo formato em login, registro, troca de residência e
 * aceite de convite.
 */
final readonly class TokenPackager
{
    public function __construct(
        private JwtIssuer $issuer,
        private RefreshTokenService $refreshTokens,
    ) {}

    /**
     * Sessão completa com refresh recém-emitido.
     *
     * @return array<string, mixed>
     */
    public function session(User $user, ?string $tenantId): array
    {
        return $this->wrap($user, $tenantId, $this->refreshTokens->issue($user));
    }

    /**
     * Sessão completa aproveitando o refresh que acabou de ser rotacionado.
     *
     * @param  array{user: User, new: array{plain: string, expires_in: int}}  $rotated
     * @return array<string, mixed>
     */
    public function renewedSession(User $user, ?string $tenantId, array $rotated): array
    {
        return $this->wrap($user, $tenantId, [
            'plain' => $rotated['new']['plain'],
            'expires_in' => $rotated['new']['expires_in'],
        ]);
    }

    /**
     * Par de tokens sem a expiração do refresh (aceite de convite).
     *
     * @return array<string, mixed>
     */
    public function tokenPair(User $user, ?string $tenantId): array
    {
        $refresh = $this->refreshTokens->issue($user);

        return $this->pair($user, $tenantId, $refresh['plain']);
    }

    /**
     * @param  array{plain: string, expires_in?: int}  $refresh
     * @return array<string, mixed>
     */
    private function wrap(User $user, ?string $tenantId, array $refresh): array
    {
        return $this->pair($user, $tenantId, $refresh['plain']) + [
            'refresh_expires_in' => $refresh['expires_in'] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pair(User $user, ?string $tenantId, string $refreshPlain): array
    {
        return $this->issuer->issue($user, $tenantId) + ['refresh_token' => $refreshPlain];
    }
}
