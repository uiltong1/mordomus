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

            return;
        }

        $config = $this->configs->find((string) $occurrence->trigger_config_id, $tenantId);

        if ($config === null) {
            return;
        }

        $base = $this->base($occurrence, $config, $tenantId);
        $next = $this->dates->nextDue($config, $base, $this->calendar->timezone($tenantId));

        $nextDueAt = DB::transaction(function () use ($config, $base, $next): ?CarbonImmutable {

            $config->last_base_date = $base->format('Y-m-d');
            $config->save();

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

    private function base(JobSchedule $occurrence, TriggerConfig $config, string $tenantId): CarbonImmutable
    {
        $day = $config->recalculate_base === TriggerConfig::RECALCULATE_COMPLETION
            ? ($occurrence->completed_at ?? CarbonImmutable::now('UTC'))
            : $occurrence->scheduled_for;

        return $this->calendar->day($day, $tenantId);
    }
}
