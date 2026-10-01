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
     * Regras do alvo, da mais antiga para a mais nova.
     *
     * @return Collection<int, TriggerConfig>
     */
    public function forSubject(string $tenantId, string $subjectType, string $subjectId): Collection;

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
     * Cria a regra já com o alvo apontado.
     *
     * `$subjectType` decide entre `asset_id` e `bill_id` dentro do repositório:
     * o CHECK de exclusão mútua do banco exige que exatamente uma das duas
     * colunas esteja preenchida, e quem sabe qual delas é a do alvo é quem
     * entende a coluna.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createFor(string $tenantId, string $subjectType, string $subjectId, array $attributes): TriggerConfig;

    /** Pausa as regras ativas do alvo; devolve quantas foram pausadas. */
    public function deactivateFor(string $tenantId, string $subjectType, string $subjectId): int;

    /** Reativa a regra; a data é recalculada por quem sabe a matemática. */
    public function activate(TriggerConfig $config): TriggerConfig;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function change(TriggerConfig $config, array $attributes): TriggerConfig;

    public function delete(TriggerConfig $config): void;
}
