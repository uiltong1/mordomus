<?php

namespace Mordomus\Identity\Http\Resources;

use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;
use Mordomus\Identity\Models\Membership;

final readonly class MemberResource
{
    public function __construct(private CapabilityResolverServiceInterface $capabilities) {}

    public function make(Membership $membership): array
    {
        $user = $membership->user;

        return [
            'id' => $membership->id,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'role' => [
                'id' => $membership->role->id,
                'key' => $membership->role->key,
                'name' => $membership->role->name,
            ],
            'status' => $membership->status,
            'capabilities' => $this->capabilities->keys($membership),
        ];
    }

    public function collection(iterable $memberships): array
    {
        return collect($memberships)
            ->map(fn (Membership $membership): array => $this->make($membership))
            ->values()
            ->all();
    }
}
