<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Materialização das ocorrências: é o motor que transforma regra em datas
 * concretas na agenda.
 */
interface OccurrenceMaterializerServiceInterface
{
    /** Preenche a janela de todas as regras ativas da residência. */
    public function materialize(string $tenantId, int $horizonDays): int;

    /**
     * Garante a ocorrência de uma data, se ela ainda não existir.
     *
     * Devolve `null` quando a data já estava materializada: o recálculo
     * pós-conclusão usa este mesmo método para o próximo ciclo, e é esse
     * `null` que mantém a conclusão repetida sem duplicar agendamento (R3).
     */
    public function materializeDate(TriggerConfig $config, CarbonImmutable $date): ?JobSchedule;
}
