<?php

namespace Mordomus\Identity\Services;

use Mordomus\Identity\Exceptions\InvalidRefreshToken;
use Mordomus\Identity\Models\RefreshToken;
use Mordomus\Identity\Models\User;

/**
 * Refresh token com rotação e detecção de reuso.
 */
class RefreshTokenService
{
    /**
     * @return array{plain: string, id: string, expires_in: int}
     */
    public function issue(User $user): array
    {
        $plain = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes((int) config('jwt.refresh_ttl', 43200));

        $token = RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt,
        ]);

        return [
            'plain' => $plain,
            'id' => $token->id,
            'expires_in' => now()->diffInSeconds($expiresAt, false),
        ];
    }

    /**
     * Consome o token atual: revoga-o, emite outro e devolve o usuário.
     * Reuso de token já rotacionado revoga a família inteira (401).
     *
     * @return array{user: User, new: array{plain: string, id: string, expires_in: int}}
     */
    public function rotate(string $plain): array
    {
        $token = RefreshToken::query()->where('token_hash', hash('sha256', $plain))->first();

        if (! $token) {
            throw InvalidRefreshToken::unknown();
        }

        if ($token->revoked_at !== null) {
            RefreshToken::query()
                ->where('user_id', $token->user_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            throw InvalidRefreshToken::reused();
        }

        if ($token->expires_at->isPast()) {
            throw InvalidRefreshToken::expired();
        }

        $user = $token->user;

        if (! $user) {
            throw InvalidRefreshToken::unknown();
        }

        $new = $this->issue($user);

        $token->update([
            'revoked_at' => now(),
            'replaced_by' => $new['id'],
        ]);

        return ['user' => $user, 'new' => $new];
    }

    /** Revoga o token (logout). */
    public function revoke(string $plain): void
    {
        RefreshToken::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
