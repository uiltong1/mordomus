<?php

declare(strict_types=1);

namespace Mordomus\Identity\Services;

use Mordomus\Identity\Contracts\Repositories\RefreshTokenRepositoryInterface;
use Mordomus\Identity\Contracts\Services\RefreshTokenServiceInterface;
use Mordomus\Identity\Exceptions\InvalidRefreshToken;
use Mordomus\Identity\Models\User;

/**
 * Refresh token com rotação e detecção de reuso.
 */
class RefreshTokenService implements RefreshTokenServiceInterface
{
    public function __construct(
        private readonly RefreshTokenRepositoryInterface $tokens,
    ) {}

    /** @return array{plain: string, id: string, expires_in: int} */
    public function issue(User $user): array
    {
        $plain = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes((int) config('jwt.refresh_ttl', 43200));

        $token = $this->tokens->create($user, hash('sha256', $plain), $expiresAt);

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
        $token = $this->tokens->findByHash(hash('sha256', $plain));

        if (! $token) {
            throw InvalidRefreshToken::unknown();
        }

        if ($token->revoked_at !== null) {
            $this->tokens->revokeActiveForUser($token->user_id);

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

        $this->tokens->markRotated($token, $new['id']);

        return ['user' => $user, 'new' => $new];
    }

    /** Revoga o token (logout). */
    public function revoke(string $plain): void
    {
        $this->tokens->revokeByHash(hash('sha256', $plain));
    }
}
