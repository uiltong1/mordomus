<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Mordomus\Identity\Models\Membership;

interface CapabilityResolverServiceInterface
{
    /** @return list<string> */
    public function keys(Membership $membership): array;

    public function allows(Membership $membership, string $capability): bool;

    public function forget(Membership $membership): void;
}
