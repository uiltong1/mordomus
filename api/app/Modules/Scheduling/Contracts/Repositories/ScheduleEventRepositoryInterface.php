<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Repositories;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Models\ScheduleEvent;

/**
 * Trilha append-only das ocorrências.
 */
interface ScheduleEventRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $tenantId,
        string $jobScheduleId,
        string $event,
        ?string $actor,
        array $payload = [],
        ?CarbonImmutable $occurredAt = null,
    ): ScheduleEvent;

    /**
     * `dedupe_key` dos avisos já publicados, indexados por ocorrência.
     *
     * É a fonte da verdade do "esse offset já disparou": um `ESCALATED` com
     * quatro offsets gera quatro linhas, e sem esta leitura o varrimento de
     * 15 min re-publicaria o mesmo aviso a cada passada. Uma query por
     * ocorrência viraria a parte dominante do tempo da passada, então a
     * leitura é em lote.
     *
     * @param  list<string>  $jobScheduleIds
     * @return array<string, list<string>>
     */
    public function noticeKeysByOccurrence(array $jobScheduleIds): array;
}
