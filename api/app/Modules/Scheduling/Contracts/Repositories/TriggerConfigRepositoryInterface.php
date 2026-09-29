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

    /** Regra de mesmo alvo e mesmo título — a chave do atalho proxied. */
    public function findByTitle(string $tenantId, string $subjectType, string $subjectId, string $title): ?TriggerConfig;

    /**
     * Regras ativas da residência, na ordem de criação.
     *
     * O motor só materializa o que está ativo: uma regra pausada
     * (`is_active = false`) preserva o histórico e volta a produzir datas
     * quando é reativada, sem que o materializador tenha de saber a diferença.
     *
     * @return Collection<int, TriggerConfig>
     */
    public function activeFor(string $tenantId): Collection;

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
