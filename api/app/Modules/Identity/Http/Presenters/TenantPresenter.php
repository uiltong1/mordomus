<?php

namespace Mordomus\Identity\Http\Presenters;

use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\TenantPreference;
use Mordomus\Identity\Services\CapabilityResolver;

/**
 * Shape da residência e das suas preferências.
 */
final readonly class TenantPresenter
{
    public function __construct(private CapabilityResolver $capabilities) {}

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
    public function preferences(Tenant $tenant): array
    {
        // o escopo global ficaria `1 = 0` no POST /tenants, que roda sem o
        // contexto de residência
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
