<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Illuminate\Http\Request;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;

interface MemberServiceInterface
{
    public function index(Request $request, Tenant $tenant): array;

    public function update(Request $request, Tenant $tenant, Membership $membership): array;

    public function updateGrants(Request $request, Tenant $tenant, Membership $membership): array;
}
