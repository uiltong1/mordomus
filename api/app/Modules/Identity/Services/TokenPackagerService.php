<?php

namespace Mordomus\Identity\Services;

use Mordomus\Identity\Contracts\Services\JwtIssuerServiceInterface;
use Mordomus\Identity\Contracts\Services\RefreshTokenServiceInterface;
use Mordomus\Identity\Contracts\Services\TokenPackagerServiceInterface;
use Mordomus\Identity\Models\User;

final readonly class TokenPackagerService implements TokenPackagerServiceInterface
{
    public function __construct(
        private JwtIssuerServiceInterface $issuer,
        private RefreshTokenServiceInterface $refreshTokens,
    ) {}

    public function session(User $user, ?string $tenantId): array
    {
        return $this->wrap($user, $tenantId, $this->refreshTokens->issue($user));
    }

    public function renewedSession(User $user, ?string $tenantId, array $rotated): array
    {
        return $this->wrap($user, $tenantId, [
            'plain' => $rotated['new']['plain'],
            'expires_in' => $rotated['new']['expires_in'],
        ]);
    }

    public function tokenPair(User $user, ?string $tenantId): array
    {
        $refresh = $this->refreshTokens->issue($user);

        return $this->pair($user, $tenantId, $refresh['plain']);
    }

    private function wrap(User $user, ?string $tenantId, array $refresh): array
    {
        return $this->pair($user, $tenantId, $refresh['plain']) + [
            'refresh_expires_in' => $refresh['expires_in'] ?? 0,
        ];
    }

    private function pair(User $user, ?string $tenantId, string $refreshPlain): array
    {
        return $this->issuer->issue($user, $tenantId) + ['refresh_token' => $refreshPlain];
    }
}
