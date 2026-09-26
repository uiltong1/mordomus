<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\User;

interface MembershipRepositoryInterface
{
    /** Residências do usuário — lista cross-tenant por definição. */
    public function paginateForUser(string $userId, OffsetPagination $pagination): LengthAwarePaginator;

    public function firstActiveForUser(User $user): ?Membership;

    public function tenantIdOfFirstActiveForUser(User $user): ?string;

    public function findActiveWithRole(User $user, string $tenantId): ?Membership;

    /** @return Collection<int, Membership> */
    public function listActiveForTenant(string $tenantId): Collection;

    public function attachRole(Membership $membership, string $roleId): Membership;

    public function syncGrant(Membership $membership, string $permissionId, bool $granted): Membership;

    public function activateForInvitation(User $user, string $tenantId, string $roleId): Membership;
}
