<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\User;

interface TokenPackagerServiceInterface
{
    /** @return array<string, mixed> */
    public function session(User $user, ?string $tenantId): array;

    /**
     * @param  array{user: User, new: array{plain: string, expires_in?: int}}  $rotated
     * @return array<string, mixed>
     */
    public function renewedSession(User $user, ?string $tenantId, array $rotated): array;

    /** @return array<string, mixed> */
    public function tokenPair(User $user, ?string $tenantId): array;
}
