<?php

namespace Mordomus\Identity\Http\Resources;

use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\TenantPreference;

final readonly class TenantResource
{
    public function __construct(private CapabilityResolverServiceInterface $capabilities) {}

    public function make(Tenant $tenant, ?Membership $membership = null): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => $tenant->timezone,
            'preferred_hour' => $tenant->preferred_hour,
            'archived' => $tenant->isArchived(),
            'archived_at' => $tenant->archived_at?->toIso8601String(),
            'role' => $membership?->role ? [
                'id' => $membership->role->id,
                'key' => $membership->role->key,
                'name' => $membership->role->name,
            ] : null,
            'capabilities' => $membership ? $this->capabilities->keys($membership) : [],
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }

    public function preferences(Tenant $tenant): array
    {

        $preferences = $tenant->preferences()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->first();

        return [
            'tenant_id' => $tenant->id,
            'preferred_hour' => $tenant->preferred_hour,
            'quiet_hours' => $preferences?->quiet_hours ?? TenantPreference::DEFAULT_QUIET_HOURS,
            'channels' => $preferences?->channels ?? TenantPreference::DEFAULT_CHANNELS,
        ];
    }
}
