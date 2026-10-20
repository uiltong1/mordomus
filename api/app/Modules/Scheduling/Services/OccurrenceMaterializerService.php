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

final class OccurrenceMaterializerService implements OccurrenceMaterializerServiceInterface
{
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

    private function refreshNextDue(TriggerConfig $config): void
    {
        $next = $this->occurrences->nextOpenFor($config);

        $config->next_due_at = $next?->due_at;
        $config->save();
    }
}
