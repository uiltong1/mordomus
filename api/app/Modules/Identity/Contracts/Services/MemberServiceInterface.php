<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Illuminate\Http\Request;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;

interface MemberServiceInterface
{
    /** @return array{data: list<array<string, mixed>>} */
    public function index(Request $request, Tenant $tenant): array;

    /** @return array{data: array<string, mixed>} */
    public function update(Request $request, Tenant $tenant, Membership $membership): array;

    /** @return array{data: array<string, mixed>} */
    public function updateGrants(Request $request, Tenant $tenant, Membership $membership): array;
}
