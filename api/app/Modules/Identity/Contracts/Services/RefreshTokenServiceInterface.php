<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\User;

interface RefreshTokenServiceInterface
{
    /**
     * @return array{plain: string, id: string, expires_in: int}
     */
    public function issue(User $user): array;

    /**
     * @return array{user: User, new: array{plain: string, id: string, expires_in: int}}
     */
    public function rotate(string $plain): array;

    public function revoke(string $plain): void;
}
