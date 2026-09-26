<?php

declare(strict_types=1);

namespace Mordomus\Identity\Repositories;

use Mordomus\Identity\Contracts\Repositories\UserRepositoryInterface;
use Mordomus\Identity\Models\User;

final class UserRepository implements UserRepositoryInterface
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function create(array $attributes): User
    {
        return User::create($attributes);
    }
}
