<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Illuminate\Http\Request;
use Mordomus\Identity\Models\Tenant;

interface TenantServiceInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function index(Request $request): array;

    /** @return array{data: array<string, mixed>} */
    public function show(Request $request, Tenant $tenant): array;

    /** @return array<string, mixed> */
    public function store(Request $request): array;

    /** @return array{data: array<string, mixed>, archived?: bool} */
    public function update(Request $request, Tenant $tenant): array;

    /** @return array{data: array<string, mixed>} */
    public function preferences(Request $request, Tenant $tenant): array;

    /** @return array{data: array<string, mixed>} */
    public function updatePreferences(Request $request, Tenant $tenant): array;
}
