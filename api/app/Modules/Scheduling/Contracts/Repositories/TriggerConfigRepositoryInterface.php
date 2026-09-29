<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /** Regra de mesmo alvo e mesmo título — a chave do atalho proxied. */
    public function findByTitle(string $tenantId, string $subjectType, string $subjectId, string $title): ?TriggerConfig;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TriggerConfig;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function change(TriggerConfig $config, array $attributes): TriggerConfig;

    public function delete(TriggerConfig $config): void;
}
