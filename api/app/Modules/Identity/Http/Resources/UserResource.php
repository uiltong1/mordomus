<?php

namespace Mordomus\Identity\Http\Resources;

use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\User;

/**
 * Shape público do usuário (perfil e sessão) — nunca expõe `password_hash`.
 */
final readonly class UserResource
{
    public function __construct(private CapabilityResolverServiceInterface $capabilities) {}

    /**
     * @return array{id: string, name: string, email: string, locale: string}
     */
    public function make(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
        ];
    }

    /**
     * Residências com membership ativo, como aparecem no payload de sessão.
     *
     * @return list<array<string, mixed>>
     */
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

    /**
     * @return list<string>
     */
    public function capabilities(Membership $membership): array
    {
        return $this->capabilities->keys($membership);
    }
}
