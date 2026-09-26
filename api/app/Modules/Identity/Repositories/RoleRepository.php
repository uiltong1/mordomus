<?php

declare(strict_types=1);

namespace Mordomus\Identity\Repositories;

use Mordomus\Http\Exceptions\ResourceNotFound;
use Mordomus\Identity\Contracts\Repositories\RoleRepositoryInterface;
use Mordomus\Identity\Models\Role;

final class RoleRepository implements RoleRepositoryInterface
{
    public function findOrFail(string $roleId): Role
    {
        $role = Role::query()->where('id', $roleId)->first();

        if ($role === null) {
            throw ResourceNotFound::make();
        }

        return $role;
    }

    public function systemByKey(string $key): Role
    {
        return Role::systemByKey($key);
    }
}
