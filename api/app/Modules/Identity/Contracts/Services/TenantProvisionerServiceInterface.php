<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\User;

interface TenantProvisionerServiceInterface
{
    public function create(User $user, array $attributes): array;
}
