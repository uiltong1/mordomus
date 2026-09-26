<?php

declare(strict_types=1);

namespace Mordomus\Identity\Services;

use Illuminate\Http\Request;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Contracts\Services\ProfileServiceInterface;
use Mordomus\Identity\Http\Resources\UserResource;
use Mordomus\Identity\Models\User;

final class ProfileService implements ProfileServiceInterface
{
    public function __construct(
        private readonly MembershipRepositoryInterface $memberships,
        private readonly UserResource $resource,
    ) {}

    public function show(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $activeTenantId = ActiveTenant::id($request);
        $activeMembership = $activeTenantId
            ? $this->memberships->findActiveWithRole($user, $activeTenantId)
            : null;

        return [
            'data' => [
                'user' => $this->resource->make($user),
                'active_tenant' => $activeTenantId,
                'tenants' => $this->resource->tenants($user),
                'capabilities' => $activeMembership ? $this->resource->capabilities($activeMembership) : [],
            ],
        ];
    }
}
