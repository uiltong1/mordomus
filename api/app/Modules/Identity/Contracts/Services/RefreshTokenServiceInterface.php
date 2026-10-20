<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\User;

interface RefreshTokenServiceInterface
{
    public function issue(User $user): array;

    public function rotate(string $plain): array;

    public function revoke(string $plain): void;
}
