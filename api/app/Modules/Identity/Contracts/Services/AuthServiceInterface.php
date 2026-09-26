<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Illuminate\Http\Request;

interface AuthServiceInterface
{
    /** @return array<string, mixed> */
    public function register(Request $request): array;

    /** @return array<string, mixed> */
    public function login(Request $request): array;

    /** @return array<string, mixed> */
    public function refresh(Request $request): array;

    /** @return array<string, mixed> */
    public function logout(Request $request): array;

    /** @return array<string, mixed> */
    public function switchTenant(Request $request): array;
}
