<?php

declare(strict_types=1);

namespace Mordomus\Identity\Services;

use Illuminate\Http\Request;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\PermissionRepositoryInterface;
use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;
use Mordomus\Identity\Contracts\Services\MemberServiceInterface;
use Mordomus\Identity\Exceptions\CannotChangeOwnGrants;
use Mordomus\Identity\Exceptions\CannotChangeOwnRole;
use Mordomus\Identity\Exceptions\MembershipNotFound;
use Mordomus\Identity\Http\Resources\MemberResource;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;

final class MemberService implements MemberServiceInterface
{
    public function __construct(
        private readonly MembershipRepositoryInterface $memberships,
        private readonly PermissionRepositoryInterface $permissions,
        private readonly CapabilityResolverServiceInterface $capabilities,
        private readonly MemberResource $resource,
    ) {}

    public function index(Request $request, Tenant $tenant): array
    {
        return [
            'data' => $this->resource->collection($this->memberships->listActiveForTenant($tenant->id)),
        ];
    }

    public function update(Request $request, Tenant $tenant, Membership $membership): array
    {
        $this->assertBelongsTo($tenant, $membership);

        if ($membership->user_id === $request->user()->id) {
            throw CannotChangeOwnRole::make();
        }

        $membership = $this->memberships->attachRole($membership, $request->input('role_id'));
        $this->capabilities->forget($membership);

        return ['data' => $this->resource->make($membership)];
    }

    public function updateGrants(Request $request, Tenant $tenant, Membership $membership): array
    {
        $this->assertBelongsTo($tenant, $membership);

        if ($membership->user_id === $request->user()->id) {
            throw CannotChangeOwnGrants::make();
        }

        $permission = $this->permissions->findOrFailByKey($request->input('capability'));

        $membership = $this->memberships->syncGrant(
            $membership,
            $permission->id,
            $request->boolean('granted'),
        );

        $this->capabilities->forget($membership);

        return ['data' => $this->resource->make($membership)];
    }

    private function assertBelongsTo(Tenant $tenant, Membership $membership): void
    {
        if ($membership->tenant_id !== $tenant->id) {
            throw MembershipNotFound::make();
        }
    }
}
