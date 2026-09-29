<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mordomus\Scheduling\Contracts\Repositories\JobScheduleRepositoryInterface;
use Mordomus\Scheduling\Contracts\Repositories\TriggerConfigRepositoryInterface;
use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Contracts\Services\OccurrenceMaterializerServiceInterface;
use Mordomus\Scheduling\Contracts\Services\OccurrenceRecalculatorServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerDateServiceInterface;
use Mordomus\Scheduling\Events\EventName;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Ciclo seguinte a uma conclusão.
 *
 * A data nasce da regra e da base do `recalculate_base` (R4) — nunca do
 * relógio do worker, que é o que mantém o ciclo preso ao dia em que a tarefa
 * foi cumprida e não ao dia em que a fila esvaziou.
 */
final class OccurrenceRecalculatorService implements OccurrenceRecalculatorServiceInterface
{
    public function __construct(
        private readonly JobScheduleRepositoryInterface $occurrences,
        private readonly TriggerConfigRepositoryInterface $configs,
        private readonly OccurrenceMaterializerServiceInterface $materializer,
        private readonly TriggerDateServiceInterface $dates,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly EventPublisherServiceInterface $publisher,
    ) {}

    public function recalculate(string $tenantId, string $occurrenceId): void
    {
        $occurrence = $this->occurrences->find($occurrenceId, $tenantId);

        if ($occurrence === null) {
            // A regra foi excluída e a ocorrência foi junto, em cascata: não há
            // ciclo a refazer, e falhar aqui só ocuparia a DLQ.
            return;
        }

        $config = $this->configs->find((string) $occurrence->trigger_config_id, $tenantId);

        if ($config === null) {
            return;
        }

        $base = $this->base($occurrence, $config, $tenantId);
        $next = $this->dates->nextDue($config, $base, $this->calendar->timezone($tenantId));

        $nextDueAt = DB::transaction(function () use ($config, $base, $next): ?CarbonImmutable {
            // String e não Carbon: `last_base_date` é dia de calendário, e
            // gravar o Carbon de meia-noite num fuso à frente do UTC gravaria
            // o dia anterior.
            $config->last_base_date = $base->format('Y-m-d');
            $config->save();

            // Regra pausada não ganha data nova — `is_active = false` significa
            // que o morador não quer nada na agenda até reativar.
            if (! $config->isActive() || $next === null) {
                return null;
            }

            $this->materializer->materializeDate($config, $next);

            return $config->next_due_at?->toImmutable();
        });

        $this->publisher->publish(EventName::OCCURRENCE_COMPLETED, $tenantId, [
            'tenant_id' => $tenantId,
            'schedule_id' => $occurrence->id,
            'trigger_config_id' => $config->id,
            'subject_type' => $config->subject_type,
            'subject_id' => $config->subjectId(),
            'completed_at' => $occurrence->completed_at?->toIso8601String(),
            'next_due_at' => $nextDueAt?->toIso8601String(),
        ]);
    }

    /**
     * Âncora do novo ciclo (R4).
     *
     * `COMPLETION` conta a partir de quando o morador entregou; `DUE_DATE`, a
     * partir do dia do vencimento — que é `scheduled_for`, e não `due_at`
     * convertido: um `preferred_hour` à meia-noite jogaria a data para o dia
     * anterior, e o ciclo andaria para trás sem a tarefa ter sido cumprida.
     */
    private function base(JobSchedule $occurrence, TriggerConfig $config, string $tenantId): CarbonImmutable
    {
        $day = $config->recalculate_base === TriggerConfig::RECALCULATE_COMPLETION
            ? ($occurrence->completed_at ?? CarbonImmutable::now('UTC'))
            : $occurrence->scheduled_for;

        return $this->calendar->day($day, $tenantId);
    }
}
