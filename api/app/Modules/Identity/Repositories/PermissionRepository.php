<?php

declare(strict_types=1);

namespace Mordomus\Identity\Repositories;

use Mordomus\Http\Exceptions\ResourceNotFound;
use Mordomus\Identity\Contracts\Repositories\PermissionRepositoryInterface;
use Mordomus\Identity\Models\Permission;

final class PermissionRepository implements PermissionRepositoryInterface
{
    public function findOrFailByKey(string $key): Permission
    {
        $permission = Permission::query()->where('key', $key)->first();

        if ($permission === null) {
            throw ResourceNotFound::make();
        }

        return $permission;
    }
}
