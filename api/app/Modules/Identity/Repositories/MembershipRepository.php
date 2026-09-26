<?php

declare(strict_types=1);

namespace Mordomus\Identity\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\User;

final class MembershipRepository implements MembershipRepositoryInterface
{
    public function paginateForUser(string $userId, OffsetPagination $pagination): LengthAwarePaginator
    {
        return Membership::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->with(['tenant', 'role'])
            ->where('user_id', $userId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);
    }

    public function firstActiveForUser(User $user): ?Membership
    {
        return $user->memberships()
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->first();
    }

    public function tenantIdOfFirstActiveForUser(User $user): ?string
    {
        $tenantId = $user->memberships()
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->value('tenant_id');

        return $tenantId === null ? null : (string) $tenantId;
    }

    public function findActiveWithRole(User $user, string $tenantId): ?Membership
    {
        return $user->memberships()
            ->with('role')
            ->where('tenant_id', $tenantId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->first();
    }

    public function listActiveForTenant(string $tenantId): Collection
    {
        return Membership::query()
            ->with(['user', 'role'])
            ->where('tenant_id', $tenantId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get();
    }

    public function attachRole(Membership $membership, string $roleId): Membership
    {
        $membership->role_id = $roleId;
        $membership->save();
        $membership->load(['user', 'role']);

        return $membership;
    }

    public function syncGrant(Membership $membership, string $permissionId, bool $granted): Membership
    {
        $membership->permissionGrants()->syncWithoutDetaching([
            $permissionId => ['granted' => $granted],
        ]);
        $membership->load(['user', 'role']);

        return $membership;
    }

    public function activateForInvitation(User $user, string $tenantId, string $roleId): Membership
    {
        return Membership::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                ],
                [
                    'role_id' => $roleId,
                    'status' => Membership::STATUS_ACTIVE,
                ],
            );
    }
}
