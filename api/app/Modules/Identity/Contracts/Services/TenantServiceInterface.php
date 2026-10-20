<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Illuminate\Http\Request;
use Mordomus\Identity\Models\Tenant;

interface TenantServiceInterface
{
    public function index(Request $request): array;

    public function show(Request $request, Tenant $tenant): array;

    public function store(Request $request): array;

    public function update(Request $request, Tenant $tenant): array;

    public function preferences(Request $request, Tenant $tenant): array;

    public function updatePreferences(Request $request, Tenant $tenant): array;
}
