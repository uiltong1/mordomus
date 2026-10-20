<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Illuminate\Http\Request;
use Mordomus\Identity\Models\Tenant;

interface InvitationServiceInterface
{
    public function store(Request $request, Tenant $tenant): array;

    public function accept(Request $request, string $token): array;
}
