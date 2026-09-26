<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Repositories;

use Mordomus\Identity\Models\Role;

interface RoleRepositoryInterface
{
    public function findOrFail(string $roleId): Role;

    public function systemByKey(string $key): Role;
}
