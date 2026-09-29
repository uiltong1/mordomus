<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Cálculo de datas do módulo Scheduling — a única matemática de recorrência
 * do produto (nenhum outro módulo calcula data futura).
 *
 * É puro de propósito: não lê banco nem fuso de ninguém. O fuso e a
 * `preferred_hour` chegam resolvidos de fora, para que o calendário seja
 * testável sem uma residência no meio.
 */
interface TriggerDateServiceInterface
{
    /**
     * Próxima data de calendário da regra, no fuso da residência.
     *
     * `$base` é a âncora do ciclo (quando a tarefa foi cumprida, ou a data
     * de vencimento, conforme `recalculate_base`). Sem âncora, cai em
     * `last_base_date` e, na falta dele, em hoje.
     *
     * Devolve `null` para `POST_COMPLETION` e `ESCALATED` sem âncora: os dois
     * medem a partir de um cumprimento que ainda não aconteceu, e inventar uma
     * data seria calcular fora do `TriggerConfig`.
     */
    public function nextDue(TriggerConfig $config, ?CarbonImmutable $base, string $timezone): ?CarbonImmutable;

    /**
     * Instante em que a ocorrência de `$scheduledFor` dispara: mesmo dia, na
     * `preferred_hour` já resolvida (config → tenant → 09:00), em UTC.
     */
    public function dueAt(CarbonImmutable $scheduledFor, string $timezone, string $preferredHour): CarbonImmutable;
}
