<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Contracts\Services;

use Illuminate\Http\Request;

interface AssetServiceInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function index(Request $request): array;

    /** @return array{data: array<string, mixed>} */
    public function show(Request $request, string $assetId): array;

    /** @return array{data: array<string, mixed>} */
    public function store(Request $request): array;

    /** @return array{data: array<string, mixed>} */
    public function update(Request $request, string $assetId): array;

    /** @return array{data: array<string, mixed>, archived: bool} */
    public function destroy(Request $request, string $assetId): array;
}
