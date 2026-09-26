<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;

interface TenantProvisionerServiceInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{tenant: Tenant, membership: Membership}
     */
    public function create(User $user, array $attributes): array;
}
