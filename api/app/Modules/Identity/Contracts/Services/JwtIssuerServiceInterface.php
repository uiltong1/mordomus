<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\User;

interface JwtIssuerServiceInterface
{
    /**
     * @param  list<string>  $tenantIds
     * @return array<string, mixed>
     */
    public function claims(string $userId, ?string $activeTenantId, array $tenantIds, int $timestamp): array;

    /** @return array{token_type: string, access_token: string, expires_in: int, active_tenant: ?string} */
    public function issue(User $user, ?string $activeTenantId = null): array;
}
