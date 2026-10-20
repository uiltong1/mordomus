<?php

namespace Mordomus\Identity\Http\Resources;

use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\User;

final readonly class UserResource
{
    public function __construct(private CapabilityResolverServiceInterface $capabilities) {}

    public function make(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
        ];
    }

    public function tenants(User $user): array
    {
        return $user->memberships()
            ->with('tenant', 'role')
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Membership $membership) => [
                'id' => $membership->tenant_id,
                'name' => $membership->tenant->name,
                'slug' => $membership->tenant->slug,
                'role' => $membership->role->name,
                'role_key' => $membership->role->key,
                'capabilities' => $this->capabilities->keys($membership),
            ])
            ->all();
    }

    public function capabilities(Membership $membership): array
    {
        return $this->capabilities->keys($membership);
    }
}
