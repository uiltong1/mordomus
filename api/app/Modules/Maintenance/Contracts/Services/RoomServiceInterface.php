<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Contracts\Services;

use Illuminate\Http\Request;

interface RoomServiceInterface
{
    public function index(Request $request): array;

    public function show(Request $request, string $roomId): array;

    public function store(Request $request): array;

    public function update(Request $request, string $roomId): array;

    public function destroy(Request $request, string $roomId): array;

    public function order(Request $request): array;
}
