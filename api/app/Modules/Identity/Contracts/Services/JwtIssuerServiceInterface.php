<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\User;

interface JwtIssuerServiceInterface
{
    public function claims(string $userId, ?string $activeTenantId, array $tenantIds, int $timestamp): array;

    public function issue(User $user, ?string $activeTenantId = null): array;
}
