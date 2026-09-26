<?php

declare(strict_types=1);

namespace Mordomus\Identity\Services;

use Illuminate\Http\Request;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Contracts\Services\TenantProvisionerServiceInterface;
use Mordomus\Identity\Contracts\Services\TenantServiceInterface;
use Mordomus\Identity\Contracts\Services\TokenPackagerServiceInterface;
use Mordomus\Identity\Http\Resources\TenantResource;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\TenantPreference;
use Mordomus\Identity\Models\User;

final class TenantService implements TenantServiceInterface
{
    public function __construct(
        private readonly MembershipRepositoryInterface $memberships,
        private readonly TenantProvisionerServiceInterface $provisioner,
        private readonly TokenPackagerServiceInterface $tokens,
        private readonly TenantResource $resource,
    ) {}

    public function index(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $pagination = OffsetPagination::from($request);

        $memberships = $this->memberships->paginateForUser((string) $user->id, $pagination);

        return [
            'data' => $memberships->getCollection()
                ->map(fn (Membership $membership): array => $this->resource->make($membership->tenant, $membership))
                ->values()
                ->all(),
            'meta' => $pagination->meta($memberships->total(), $memberships->lastPage()),
        ];
    }

    public function show(Request $request, Tenant $tenant): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'data' => $this->resource->make($tenant, $this->membershipOf($user, $tenant)),
        ];
    }

    public function store(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $provisioned = $this->provisioner->create($user, [
            'name' => $request->input('name'),
            'timezone' => $request->input('timezone'),
            'preferred_hour' => $request->input('preferred_hour'),
        ]);

        $tenant = $provisioned['tenant'];

        return [
            'data' => $this->resource->make($tenant, $provisioned['membership']),
            'active_tenant' => $tenant->id,
        ] + $this->tokens->session($user, $tenant->id);
    }

    public function update(Request $request, Tenant $tenant): array
    {
        if ($request->boolean('archived')) {
            $tenant->archive();

            return [
                'data' => $this->resource->make($tenant),
                'archived' => true,
            ];
        }

        $tenant->fill($request->only(['name', 'timezone', 'preferred_hour']));
        $tenant->save();

        /** @var User $user */
        $user = $request->user();

        return [
            'data' => $this->resource->make($tenant, $this->membershipOf($user, $tenant)),
        ];
    }

    public function preferences(Request $request, Tenant $tenant): array
    {
        return ['data' => $this->resource->preferences($tenant)];
    }

    public function updatePreferences(Request $request, Tenant $tenant): array
    {
        if ($request->has('preferred_hour')) {
            $tenant->preferred_hour = $request->input('preferred_hour');
            $tenant->save();
        }

        $preferences = $tenant->preferences ?: $tenant->preferences()->make([
            'quiet_hours' => TenantPreference::DEFAULT_QUIET_HOURS,
            'channels' => TenantPreference::DEFAULT_CHANNELS,
        ]);

        $preferences->fill($request->only(['quiet_hours', 'channels']));
        $preferences->save();

        return ['data' => $this->resource->preferences($tenant)];
    }

    private function membershipOf(User $user, Tenant $tenant): ?Membership
    {
        return $this->memberships->findActiveWithRole($user, $tenant->id);
    }
}
