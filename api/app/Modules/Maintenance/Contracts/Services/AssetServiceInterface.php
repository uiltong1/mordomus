<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Contracts\Services;

use Illuminate\Http\Request;

interface AssetServiceInterface
{
    public function index(Request $request): array;

    public function show(Request $request, string $assetId): array;

    public function store(Request $request): array;

    public function update(Request $request, string $assetId): array;

    public function destroy(Request $request, string $assetId): array;

    public function schedule(Request $request, string $assetId): array;

    public function completeOccurrence(Request $request, string $occurrenceId): array;

    public function skipOccurrence(Request $request, string $occurrenceId): array;
}
