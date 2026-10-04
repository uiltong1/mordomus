<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Mordomus\Scheduling\Contracts\Repositories\JobScheduleRepositoryInterface;
use Mordomus\Scheduling\Contracts\Repositories\ScheduleEventRepositoryInterface;
use Mordomus\Scheduling\Contracts\Repositories\TriggerConfigRepositoryInterface;
use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Contracts\Services\OccurrenceMaterializerServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerDateServiceInterface;
use Mordomus\Scheduling\Events\EventName;
use Mordomus\Scheduling\Exceptions\IncompleteTriggerRule;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\ScheduleEvent;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Preenche a agenda da residência com as ocorrências das regras ativas.
 *
 * O motor nunca guarda "a próxima data" só como número: a data vira linha de
 * `job_schedules`, e é dela que o morador, o varrimento de avisos e os
 * consumidores de evento tiram tudo o que precisam.
 */
final class OccurrenceMaterializerService implements OccurrenceMaterializerServiceInterface
{
    /**
     * Teto de datas avaliadas por regra, numa execução.
     *
     * O laço avança a regra data a data para chegar na janela; uma regra
     * esquecida há anos com intervalo de 1 dia passaria por milhares de datas
     * descartadas antes de chegar em hoje. O teto corta o trabalho
     * patológico e o restante fica para a execução seguinte — nenhuma data é
     * perdida, só adiada.
     */
    private const MAX_DATES_PER_CONFIG = 400;

    public function __construct(
        private readonly TriggerConfigRepositoryInterface $configs,
        private readonly JobScheduleRepositoryInterface $occurrences,
        private readonly ScheduleEventRepositoryInterface $trail,
        private readonly TriggerDateServiceInterface $dates,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly EventPublisherServiceInterface $publisher,
    ) {}

    public function materialize(string $tenantId, int $horizonDays): int
    {
        $created = 0;

        foreach ($this->configs->activeFor($tenantId) as $config) {
            $created += $this->fillWindow($config, $tenantId, $horizonDays);
        }

        return $created;
    }

    public function materializeDate(TriggerConfig $config, CarbonImmutable $date): ?JobSchedule
    {
        $tenantId = (string) $config->tenant_id;
        $dueAt = $this->dueAt($config, $tenantId, $date);

        $occurrence = $this->occurrences->createIfAbsent([
            'tenant_id' => $tenantId,
            'trigger_config_id' => $config->id,
            'scheduled_for' => $date->format('Y-m-d'),
            'due_at' => $dueAt,
        ]);

        if ($occurrence === null) {
            return null;
        }

        $this->trail->record($tenantId, $occurrence->id, ScheduleEvent::CREATED, null, [
            'scheduled_for' => $date->format('Y-m-d'),
            'due_at' => $dueAt->toIso8601String(),
        ]);

        $this->publisher->publish(EventName::OCCURRENCE_CREATED, $tenantId, [
            'tenant_id' => $tenantId,
            'schedule_id' => $occurrence->id,
            'trigger_config_id' => $config->id,
            'subject_type' => $config->subject_type,
            'subject_id' => $config->subjectId(),
            'scheduled_for' => $date->format('Y-m-d'),
            'due_at' => $dueAt->toIso8601String(),
        ]);

        $this->refreshNextDue($config);

        return $occurrence;
    }

    /**
     * Percorre a janela da regra a partir da última ocorrência materializada.
     *
     * Começar da última linha (e não de hoje) é o que mantém o ritmo sem
     * lacuna quando o intervalo é curto: com um dia de intervalo e janela de
     * 45 dias, refazer a lista do zero a cada execução gastaria 45 avaliações
     * para criar uma data.
     */
    private function fillWindow(TriggerConfig $config, string $tenantId, int $horizonDays): int
    {
        $timezone = $this->calendar->timezone($tenantId);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $horizonEnd = $today->addDays(max($horizonDays, $config->noticeLeadDays()));

        $latest = $this->occurrences->latestFor($config);
        $date = $latest === null
            ? $this->nextDue($config, null, $timezone, $tenantId)
            : $this->nextAfter($config, $this->calendar->day($latest->scheduled_for, $tenantId), $timezone);

        if ($date === null) {
            return 0;
        }

        $created = 0;

        for ($step = 0; $step < self::MAX_DATES_PER_CONFIG; $step++) {
            if ($date->greaterThan($horizonEnd)) {
                break;
            }

            // Data passada não é recuperada: o morador não pode concluir em
            // retrospecto, e a linha ficaria para sempre aberta.
            if ($date->greaterThanOrEqualTo($today) && $this->materializeDate($config, $date) !== null) {
                $created++;
            }

            $next = $this->nextAfter($config, $date, $timezone);

            if ($next === null || $next->equalTo($date)) {
                break;
            }

            $date = $next;
        }

        if ($step === self::MAX_DATES_PER_CONFIG) {
            Log::warning('scheduling.materialize_limit_reached', [
                'tenant_id' => $tenantId,
                'trigger_config_id' => $config->id,
            ]);
        }

        return $created;
    }

    /**
     * Próxima data do ciclo depois de `$from`.
     *
     * `CALENDAR_MONTHLY` é dia fixo do mês, não dia relativo: somar um mês a
     * um dia 31 cairia em 28 de fevereiro e o ciclo ficaria preso ali. A data
     * seguinte é a do mesmo dia no mês que vem, e o `TriggerDateService`
     * continua sendo quem decide o dia.
     */
    private function nextAfter(TriggerConfig $config, CarbonImmutable $from, string $timezone): ?CarbonImmutable
    {
        $base = $config->type === TriggerConfig::TYPE_CALENDAR_MONTHLY
            ? $from->addMonthNoOverflow()->startOfMonth()
            : $from;

        return $this->nextDue($config, $base, $timezone, (string) $config->tenant_id);
    }

    private function nextDue(TriggerConfig $config, ?CarbonImmutable $base, string $timezone, string $tenantId): ?CarbonImmutable
    {
        try {
            return $this->dates->nextDue($config, $base, $timezone);
        } catch (IncompleteTriggerRule $exception) {
            // Regra gravada com o conjunto de campos do tipo incompleto é dado
            // legado: deixar a exceção subir abortaria a materialização das
            // outras regras da mesma residência. Fica registrado e a regra
            // espera a correção.
            Log::warning('scheduling.materialize_incomplete', [
                'tenant_id' => $tenantId,
                'trigger_config_id' => $config->id,
                'type' => $config->type,
                'missing' => $exception->details()['missing'] ?? [],
            ]);

            return null;
        }
    }

    private function dueAt(TriggerConfig $config, string $tenantId, CarbonImmutable $date): CarbonImmutable
    {
        return $this->dates->dueAt(
            $date,
            $this->calendar->timezone($tenantId),
            $this->calendar->preferredHour($config->preferred_hour, $tenantId),
        );
    }

    /** `next_due_at` da regra espelha a primeira ocorrência ainda aberta. */
    private function refreshNextDue(TriggerConfig $config): void
    {
        $next = $this->occurrences->nextOpenFor($config);

        $config->next_due_at = $next?->due_at;
        $config->save();
    }
}
