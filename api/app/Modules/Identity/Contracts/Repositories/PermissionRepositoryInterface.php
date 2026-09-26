<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Repositories;

use Mordomus\Identity\Models\Permission;

interface PermissionRepositoryInterface
{
    public function findOrFailByKey(string $key): Permission;
}
