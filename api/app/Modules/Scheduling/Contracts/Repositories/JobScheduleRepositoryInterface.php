<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

interface JobScheduleRepositoryInterface
{
    public function paginate(
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $subjectType,
        ?string $status,
        OffsetPagination $pagination,
    ): LengthAwarePaginator;

    public function findOrFail(string $occurrenceId, string $tenantId): JobSchedule;

    public function find(string $occurrenceId, string $tenantId): ?JobSchedule;

    public function dueForNotice(string $tenantId, CarbonImmutable $from, CarbonImmutable $until): Collection;

    public function createIfAbsent(array $attributes): ?JobSchedule;

    public function forSubject(string $tenantId, string $subjectType): Collection;

    public function markNotified(JobSchedule $occurrence, CarbonImmutable $at): bool;

    public function markCompleted(JobSchedule $occurrence, CarbonImmutable $at, string $userId): bool;

    public function markSkipped(JobSchedule $occurrence): bool;

    public function markOverdue(string $tenantId, CarbonImmutable $before): int;

    public function latestFor(TriggerConfig $config): ?JobSchedule;

    public function nextOpenFor(TriggerConfig $config): ?JobSchedule;
}
