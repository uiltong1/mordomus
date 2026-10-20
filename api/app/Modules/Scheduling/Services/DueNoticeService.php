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

final class DueNoticeService implements DueNoticeServiceInterface
{
    private const KIND_ADVANCE_NOTICE = 'advance_notice';

    private const KIND_DUE = 'due';

    private const KIND_OFFSET = 'offset';

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
