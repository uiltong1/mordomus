<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Scheduling\Models\TriggerConfig;

interface TriggerConfigRepositoryInterface
{
    public function paginate(
        ?string $subjectType,
        ?string $subjectId,
        OffsetPagination $pagination,
    ): LengthAwarePaginator;

    public function findOrFail(string $triggerConfigId, ?string $tenantId): TriggerConfig;

    public function find(string $triggerConfigId, ?string $tenantId): ?TriggerConfig;

    public function findByTitle(string $tenantId, string $subjectType, string $subjectId, string $title): ?TriggerConfig;

    public function forSubject(string $tenantId, string $subjectType, string $subjectId): Collection;

    public function activeFor(string $tenantId): Collection;

    public function create(array $attributes): TriggerConfig;

    public function createFor(string $tenantId, string $subjectType, string $subjectId, array $attributes): TriggerConfig;

    public function deactivateFor(string $tenantId, string $subjectType, string $subjectId): int;

    public function activate(TriggerConfig $config): TriggerConfig;

    public function change(TriggerConfig $config, array $attributes): TriggerConfig;

    public function delete(TriggerConfig $config): void;
}
