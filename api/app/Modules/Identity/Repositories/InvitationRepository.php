<?php

declare(strict_types=1);

namespace Mordomus\Identity\Repositories;

use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Identity\Contracts\Repositories\InvitationRepositoryInterface;
use Mordomus\Identity\Models\Invitation;

final class InvitationRepository implements InvitationRepositoryInterface
{
    public function findByTokenHash(string $tokenHash): ?Invitation
    {
        return Invitation::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->where('token_hash', $tokenHash)
            ->with(['tenant', 'role'])
            ->first();
    }

    public function replacePending(string $tenantId, string $email): void
    {
        Invitation::query()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();
    }

    public function create(array $attributes): Invitation
    {
        return Invitation::create($attributes);
    }

    public function markAccepted(Invitation $invitation): void
    {
        $invitation->forceFill(['accepted_at' => now()])->save();
    }
}
