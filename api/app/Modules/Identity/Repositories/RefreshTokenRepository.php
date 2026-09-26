<?php

declare(strict_types=1);

namespace Mordomus\Identity\Repositories;

use DateTimeInterface;
use Mordomus\Identity\Contracts\Repositories\RefreshTokenRepositoryInterface;
use Mordomus\Identity\Models\RefreshToken;
use Mordomus\Identity\Models\User;

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function create(User $user, string $tokenHash, DateTimeInterface $expiresAt): RefreshToken
    {
        return RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        return RefreshToken::query()->where('token_hash', $tokenHash)->first();
    }

    public function revokeActiveForUser(string $userId): void
    {
        RefreshToken::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeByHash(string $tokenHash): void
    {
        RefreshToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function markRotated(RefreshToken $token, string $replacedBy): void
    {
        $token->update([
            'revoked_at' => now(),
            'replaced_by' => $replacedBy,
        ]);
    }
}
