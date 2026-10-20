<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Illuminate\Http\Request;

interface AuthServiceInterface
{
    public function register(Request $request): array;

    public function login(Request $request): array;

    public function refresh(Request $request): array;

    public function logout(Request $request): array;

    public function switchTenant(Request $request): array;
}
