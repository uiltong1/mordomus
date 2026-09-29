<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Mordomus\Scheduling\Contracts\Repositories\ScheduleEventRepositoryInterface;
use Mordomus\Scheduling\Models\ScheduleEvent;

final class ScheduleEventRepository implements ScheduleEventRepositoryInterface
{
    public function record(
        string $tenantId,
        string $jobScheduleId,
        string $event,
        ?string $actor,
        array $payload = [],
        ?CarbonImmutable $occurredAt = null,
    ): ScheduleEvent {
        return ScheduleEvent::create([
            'tenant_id' => $tenantId,
            'job_schedule_id' => $jobScheduleId,
            'event' => $event,
            'actor' => $actor,
            'payload' => $payload === [] ? null : $payload,
            'occurred_at' => $occurredAt ?? CarbonImmutable::now('UTC'),
        ]);
    }

    public function noticeKeysByOccurrence(array $jobScheduleIds): array
    {
        if ($jobScheduleIds === []) {
            return [];
        }

        return ScheduleEvent::query()
            ->whereIn('job_schedule_id', $jobScheduleIds)
            ->where('event', ScheduleEvent::NOTIFIED)
            ->get()
            ->groupBy('job_schedule_id')
            ->map(fn (Collection $events): array => $events
                ->map(fn (ScheduleEvent $event): ?string => $event->payload['dedupe_key'] ?? null)
                ->filter()
                ->values()
                ->all())
            ->all();
    }
}
