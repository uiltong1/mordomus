<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Scheduling\Contracts\Repositories\JobScheduleRepositoryInterface;
use Mordomus\Scheduling\Exceptions\OccurrenceNotFound;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

final class JobScheduleRepository implements JobScheduleRepositoryInterface
{
    public function paginate(
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $subjectType,
        ?string $status,
        OffsetPagination $pagination,
    ): LengthAwarePaginator {
        $query = JobSchedule::query()->with('triggerConfig');

        if ($from !== null) {
            $query->where('due_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('due_at', '<', $to);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($subjectType !== null) {
            $query->whereHas('triggerConfig', fn ($trigger) => $trigger->where('subject_type', $subjectType));
        }

        return $query
            ->orderBy('due_at')
            ->orderBy('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);
    }

    public function findOrFail(string $occurrenceId, string $tenantId): JobSchedule
    {
        return $this->find($occurrenceId, $tenantId) ?? throw OccurrenceNotFound::make(['occurrence_id' => $occurrenceId]);
    }

    public function find(string $occurrenceId, string $tenantId): ?JobSchedule
    {
        return JobSchedule::query()
            ->with('triggerConfig')
            ->where('id', $occurrenceId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function dueForNotice(string $tenantId, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return JobSchedule::query()
            ->with('triggerConfig')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [
                JobSchedule::STATUS_PENDING,
                JobSchedule::STATUS_NOTIFIED,
                JobSchedule::STATUS_OVERDUE,
            ])
            ->whereBetween('due_at', [$from, $until])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();
    }

    public function createIfAbsent(array $attributes): ?JobSchedule
    {
        $date = (string) $attributes['scheduled_for'];
        $configId = (string) $attributes['trigger_config_id'];

        // A checagem evita a escrita desnecessária no regime normal (o
        // materializador roda de novo todo dia e quase nada é novo); o
        // `insertOrIgnore` é quem garante a atomicidade quando dois workers
        // materializam a mesma data ao mesmo tempo.
        if ($this->findByDate($configId, $date) !== null) {
            return null;
        }

        // Minúsculo como o `HasUlids` do Eloquent: a linha entra por
        // `insertOrIgnore` (e não pelo model) e o id precisa ficar na mesma
        // forma dos ids gerados pelos outros caminhos de escrita.
        $id = strtolower((string) Str::ulid());

        $inserted = DB::table('job_schedules')->insertOrIgnore([
            'id' => $id,
            'tenant_id' => $attributes['tenant_id'],
            'trigger_config_id' => $configId,
            'scheduled_for' => $date,
            'due_at' => $attributes['due_at'],
            'status' => JobSchedule::STATUS_PENDING,
            'created_at' => $now = CarbonImmutable::now('UTC'),
            'updated_at' => $now,
        ]);

        return $inserted === 1
            ? JobSchedule::query()->with('triggerConfig')->find($id)
            : null;
    }

    public function forSubject(string $tenantId, string $subjectType): Collection
    {
        return JobSchedule::query()
            ->with('triggerConfig')
            ->where('tenant_id', $tenantId)
            ->whereHas('triggerConfig', fn ($trigger) => $trigger->where('subject_type', $subjectType))
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();
    }

    public function markNotified(JobSchedule $occurrence, CarbonImmutable $at): bool
    {
        // Só a primeira passagem escreve o relógio: num `ESCALATED` os offsets
        // seguintes republicam aviso sem reescrever `notified_at`, que é o
        // instante em que a ocorrência ficou visível para o morador.
        return JobSchedule::query()
            ->whereKey($occurrence->id)
            ->whereIn('status', [JobSchedule::STATUS_PENDING, JobSchedule::STATUS_NOTIFIED])
            ->whereNull('notified_at')
            ->update([
                'status' => JobSchedule::STATUS_NOTIFIED,
                'notified_at' => $at,
            ]) === 1;
    }

    public function markCompleted(JobSchedule $occurrence, CarbonImmutable $at, string $userId): bool
    {
        return JobSchedule::query()
            ->whereKey($occurrence->id)
            ->whereIn('status', JobSchedule::OPEN_STATUSES)
            ->update([
                'status' => JobSchedule::STATUS_COMPLETED,
                'completed_at' => $at,
                'completed_by' => $userId,
            ]) === 1;
    }

    public function markSkipped(JobSchedule $occurrence): bool
    {
        return JobSchedule::query()
            ->whereKey($occurrence->id)
            ->whereIn('status', JobSchedule::OPEN_STATUSES)
            ->update(['status' => JobSchedule::STATUS_SKIPPED]) === 1;
    }

    public function markOverdue(string $tenantId, CarbonImmutable $before): int
    {
        return JobSchedule::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [JobSchedule::STATUS_PENDING, JobSchedule::STATUS_NOTIFIED])
            ->where('due_at', '<', $before)
            ->update(['status' => JobSchedule::STATUS_OVERDUE]);
    }

    public function latestFor(TriggerConfig $config): ?JobSchedule
    {
        return JobSchedule::query()
            ->where('trigger_config_id', $config->id)
            ->orderByDesc('scheduled_for')
            ->first();
    }

    public function nextOpenFor(TriggerConfig $config): ?JobSchedule
    {
        return JobSchedule::query()
            ->where('trigger_config_id', $config->id)
            ->whereIn('status', JobSchedule::OPEN_STATUSES)
            ->orderBy('due_at')
            ->orderBy('id')
            ->first();
    }

    private function findByDate(string $triggerConfigId, string $scheduledFor): ?JobSchedule
    {
        return JobSchedule::query()
            ->where('trigger_config_id', $triggerConfigId)
            ->where('scheduled_for', $scheduledFor)
            ->first();
    }
}
