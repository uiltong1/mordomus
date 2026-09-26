<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Repositories;

use Mordomus\Identity\Models\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User;
}
