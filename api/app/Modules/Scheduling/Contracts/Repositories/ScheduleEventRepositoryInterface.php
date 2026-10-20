<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Repositories;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Models\ScheduleEvent;

interface ScheduleEventRepositoryInterface
{
    public function record(
        string $tenantId,
        string $jobScheduleId,
        string $event,
        ?string $actor,
        array $payload = [],
        ?CarbonImmutable $occurredAt = null,
    ): ScheduleEvent;

    public function noticeKeysByOccurrence(array $jobScheduleIds): array;
}
