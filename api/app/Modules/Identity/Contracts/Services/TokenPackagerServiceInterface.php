<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\User;

interface TokenPackagerServiceInterface
{
    public function session(User $user, ?string $tenantId): array;

    public function renewedSession(User $user, ?string $tenantId, array $rotated): array;

    public function tokenPair(User $user, ?string $tenantId): array;
}
