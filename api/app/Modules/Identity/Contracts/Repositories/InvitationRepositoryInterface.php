<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Repositories;

use Mordomus\Identity\Models\Invitation;

interface InvitationRepositoryInterface
{
    public function findByTokenHash(string $tokenHash): ?Invitation;

    public function replacePending(string $tenantId, string $email): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Invitation;

    public function markAccepted(Invitation $invitation): void;
}
