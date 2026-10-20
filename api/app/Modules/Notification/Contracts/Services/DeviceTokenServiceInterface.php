<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Illuminate\Http\Request;

interface DeviceTokenServiceInterface
{
    public function index(Request $request): array;

    public function store(Request $request): array;

    public function destroy(Request $request): array;

    public function test(Request $request): array;
}
