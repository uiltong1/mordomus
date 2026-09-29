<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Mordomus\Scheduling\Contracts\Repositories\JobScheduleRepositoryInterface;
use Mordomus\Scheduling\Contracts\Repositories\ScheduleEventRepositoryInterface;
use Mordomus\Scheduling\Contracts\Repositories\TriggerConfigRepositoryInterface;
use Mordomus\Scheduling\Contracts\Services\DueNoticeServiceInterface;
use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerDateServiceInterface;
use Mordomus\Scheduling\Events\EventName;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\ScheduleEvent;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Publica `schedule.due` para tudo que já venceu o aviso.
 *
 * Roda de 15 em 15 minutos, então a idempotência não é detalhe: a mesma
 * ocorrência é vista em dezenas de passadas. O que separa "aviso novo" de
 * "aviso já publicado" é o `dedupe_key` gravado na trilha, e não um flag na
 * ocorrência — uma regra `ESCALATED` tem um aviso por offset, e um único
 * `notified_at` não dá conta de quatro instantes.
 */
final class DueNoticeService implements DueNoticeServiceInterface
{
    /** Aviso único, `advance_notice_days` antes do vencimento. */
    private const KIND_ADVANCE_NOTICE = 'advance_notice';

    /** Aviso no dia do vencimento. */
    private const KIND_DUE = 'due';

    /** Um dos offsets de uma regra `ESCALATED`. */
    private const KIND_OFFSET = 'offset';

    /**
     * Atraso de aviso ainda publicável, em dias.
     *
     * O recorte exato (`due_at` no instante do aviso) deixaria de fora o aviso
     * de uma passada que o scheduler não rodou: um container reiniciado às
     * 09:00 perderia para sempre o `due` das 12:00. Um dia de tolerância
     * absorve a queda do processo e faz o varrimento se recuperar sozinho — a
     * `dedupe_key` garante que o que já saiu não sai de novo.
     */
    private const MISSED_NOTICE_GRACE_DAYS = 1;

    public function __construct(
        private readonly TriggerConfigRepositoryInterface $configs,
        private readonly JobScheduleRepositoryInterface $occurrences,
        private readonly ScheduleEventRepositoryInterface $trail,
        private readonly TriggerDateServiceInterface $dates,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly EventPublisherServiceInterface $publisher,
    ) {}

    public function publish(string $tenantId, CarbonImmutable $now): int
    {
        $published = $this->publishNotices($tenantId, $now);
        $overdue = $this->occurrences->markOverdue($tenantId, $now);

        if ($overdue > 0) {
            Log::info('scheduling.occurrences_overdue', [
                'tenant_id' => $tenantId,
                'occurrences' => $overdue,
            ]);
        }

        return $published;
    }

    private function publishNotices(string $tenantId, CarbonImmutable $now): int
    {
        $configs = $this->configs->activeFor($tenantId);

        if ($configs->isEmpty()) {
            return 0;
        }

        // O recorte é a extensão de aviso da residência: o limite superior
        // garante que nenhum aviso por vir fique de fora, e o inferior é o
        // alcance pelo avesso — o alerta de atraso de um `ESCALATED` vive
        // depois de `due_at`, e é o piso que evita que o histórico inteiro de
        // ocorrências vencidas entre em cada passada de 15 min. É o único
        // filtro que o índice `(tenant_id, status, due_at)` atende.
        $lead = $configs->max(fn (TriggerConfig $config): int => $config->noticeLeadDays());
        $tail = $configs->max(fn (TriggerConfig $config): int => $config->noticeTailDays());
        $candidates = $this->occurrences->dueForNotice(
            $tenantId,
            $now->subDays(max($tail, self::MISSED_NOTICE_GRACE_DAYS)),
            $now->addDays($lead),
        );

        if ($candidates->isEmpty()) {
            return 0;
        }

        $published = $this->trail->noticeKeysByOccurrence($candidates->modelKeys());
        $count = 0;

        foreach ($candidates as $occurrence) {
            $config = $occurrence->triggerConfig;

            if ($config === null || ! $config->isActive()) {
                continue;
            }

            $count += $this->publishOccurrence($tenantId, $occurrence, $config, $now, $published[$occurrence->id] ?? []);
        }

        return $count;
    }

    /** @param  list<string>  $already  `dedupe_key` já publicados da ocorrência */
    private function publishOccurrence(
        string $tenantId,
        JobSchedule $occurrence,
        TriggerConfig $config,
        CarbonImmutable $now,
        array $already,
    ): int {
        $timezone = $this->calendar->timezone($tenantId);
        $published = 0;

        foreach ($config->noticeOffsets() as $offset) {
            $kind = $this->kind($config, $offset);
            $slot = $kind === self::KIND_OFFSET ? self::KIND_OFFSET.':'.$offset : $kind;
            $dedupeKey = sprintf('tenant:%s:%s:%s', $tenantId, $occurrence->id, $slot);

            if (in_array($dedupeKey, $already, true)) {
                continue;
            }

            $at = $this->dates->dueAt(
                $this->calendar->day($occurrence->scheduled_for, $tenantId)->addDays($offset),
                $timezone,
                $this->calendar->preferredHour($config->preferred_hour, $tenantId),
            );

            if ($at->greaterThan($now)) {
                continue;
            }

            $this->trail->record($tenantId, $occurrence->id, ScheduleEvent::NOTIFIED, null, [
                'kind' => $kind,
                'offset' => $offset,
                'dedupe_key' => $dedupeKey,
            ], $at);

            $this->occurrences->markNotified($occurrence, $at);

            $this->publisher->publish(EventName::SCHEDULE_DUE, $tenantId, [
                'tenant_id' => $tenantId,
                'schedule_id' => $occurrence->id,
                'trigger_config_id' => $config->id,
                'subject_type' => $config->subject_type,
                'subject_id' => $config->subjectId(),
                'kind' => $kind,
                'title' => $config->title,
                'due_at' => $occurrence->due_at->utc()->toIso8601String(),
                'scheduled_for' => $occurrence->scheduled_for->format('Y-m-d'),
                'offsets' => $config->type === TriggerConfig::TYPE_ESCALATED ? $offset : null,
                'dedupe_key' => $dedupeKey,
            ]);

            $published++;
        }

        return $published;
    }

    private function kind(TriggerConfig $config, int $offset): string
    {
        return match (true) {
            $config->type === TriggerConfig::TYPE_ESCALATED => self::KIND_OFFSET,
            $offset < 0 => self::KIND_ADVANCE_NOTICE,
            default => self::KIND_DUE,
        };
    }
}
