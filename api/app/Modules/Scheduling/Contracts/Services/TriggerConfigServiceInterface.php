<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Illuminate\Http\Request;
use Mordomus\Scheduling\Models\TriggerConfig;

interface TriggerConfigServiceInterface
{
    public function index(Request $request): array;

    public function show(Request $request, string $triggerConfigId): array;

    public function store(Request $request, string $subjectType, string $subjectId): array;

    public function update(Request $request, string $triggerConfigId): array;

    public function destroy(Request $request, string $triggerConfigId): array;

    public function storeForSubject(string $tenantId, string $subjectType, string $subjectId, array $attributes): TriggerConfig;

    public function storeExclusiveForSubject(string $tenantId, string $subjectType, string $subjectId, array $attributes): TriggerConfig;

    public function pauseForSubject(string $tenantId, string $subjectType, string $subjectId): int;

    public function resumeForSubject(string $tenantId, string $subjectType, string $subjectId): int;

    public function storeForAsset(Request $request, string $assetId): array;

    public function preview(Request $request): array;
}
