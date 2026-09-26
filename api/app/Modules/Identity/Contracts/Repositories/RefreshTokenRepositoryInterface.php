<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Repositories;

use DateTimeInterface;
use Mordomus\Identity\Models\RefreshToken;
use Mordomus\Identity\Models\User;

interface RefreshTokenRepositoryInterface
{
    public function create(User $user, string $tokenHash, DateTimeInterface $expiresAt): RefreshToken;

    public function findByHash(string $tokenHash): ?RefreshToken;

    public function revokeActiveForUser(string $userId): void;

    public function revokeByHash(string $tokenHash): void;

    public function markRotated(RefreshToken $token, string $replacedBy): void;
}
