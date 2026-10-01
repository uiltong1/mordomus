<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Ocorrências materializadas e as transições de status.
 *
 * Toda transição devolve `bool` — o `false` é o que torna a conclusão
 * idempotente: a atualização é condicional ao status de origem, então a
 * segunda chamada não tem linha para alterar e o chamador sai sem refazer o
 * cálculo do ciclo.
 */
interface JobScheduleRepositoryInterface
{
    /**
     * @param  CarbonImmutable|null  $from  instante inicial inclusivo
     * @param  CarbonImmutable|null  $to  instante final exclusivo
     */
    public function paginate(
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $subjectType,
        ?string $status,
        OffsetPagination $pagination,
    ): LengthAwarePaginator;

    public function findOrFail(string $occurrenceId, string $tenantId): JobSchedule;

    public function find(string $occurrenceId, string $tenantId): ?JobSchedule;

    /**
     * Ocorrências com aviso possivelmente vencido na janela `[$from, $until]`.
     *
     * `overdue` entra na lista porque um offset positivo de `ESCALATED`
     * dispara depois do vencimento: sem ele, a virada de status engoliria os
     * avisos de atraso da própria casa. `completed` e `skipped` ficam de fora —
     * o ciclo já fechou e não há mais o que anunciar.
     *
     * @return Collection<int, JobSchedule>
     */
    public function dueForNotice(string $tenantId, CarbonImmutable $from, CarbonImmutable $until): Collection;

    /**
     * Cria a ocorrência se ainda não existir para `(regra, data)`.
     *
     * Devolve `null` quando a data já está materializada: é o que mantém o
     * recálculo idempotente mesmo com o worker rodando em paralelo.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createIfAbsent(array $attributes): ?JobSchedule;

    /**
     * Ocorrências de um alvo, da mais antiga para a mais nova.
     *
     * É a leitura que o consumidor usa para reconciliar o lado dele: o evento
     * é o caminho rápido, e esta lista é o que garante que nada se perca
     * quando o consumidor falhou depois de a ocorrência existir.
     *
     * @return Collection<int, JobSchedule>
     */
    public function forSubject(string $tenantId, string $subjectType): Collection;

    public function markNotified(JobSchedule $occurrence, CarbonImmutable $at): bool;

    public function markCompleted(JobSchedule $occurrence, CarbonImmutable $at, string $userId): bool;

    public function markSkipped(JobSchedule $occurrence): bool;

    /** Quantas ocorrências pendentes/notified já venceram e viraram `overdue`. */
    public function markOverdue(string $tenantId, CarbonImmutable $before): int;

    public function latestFor(TriggerConfig $config): ?JobSchedule;

    /** Primeira ocorrência da regra que ainda está aberta — o `next_due_at` que a regra espelha. */
    public function nextOpenFor(TriggerConfig $config): ?JobSchedule;
}
