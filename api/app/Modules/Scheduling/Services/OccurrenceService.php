<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Scheduling\Contracts\Repositories\JobScheduleRepositoryInterface;
use Mordomus\Scheduling\Contracts\Repositories\ScheduleEventRepositoryInterface;
use Mordomus\Scheduling\Contracts\Services\OccurrenceServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Exceptions\OccurrenceAlreadyFinalized;
use Mordomus\Scheduling\Exceptions\OccurrenceNotFound;
use Mordomus\Scheduling\Http\Resources\OccurrenceResource;
use Mordomus\Scheduling\Jobs\RecalculateNextOccurrence;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\ScheduleEvent;

/**
 * Agenda da residência e as duas transições do morador.
 *
 * A conclusão grava o check-in e devolve na hora; o cálculo do próximo ciclo
 * fica na fila. É o que mantém o card respondendo mesmo com o motor ocupado,
 * e `occurrence.completed` avisa o consumidor assim que a data nova existe.
 */
final class OccurrenceService implements OccurrenceServiceInterface
{
    public function __construct(
        private readonly JobScheduleRepositoryInterface $occurrences,
        private readonly ScheduleEventRepositoryInterface $trail,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly OccurrenceResource $resource,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $pagination = OffsetPagination::from($request);
        $timezone = $this->calendar->timezone($tenantId);

        $occurrences = $this->occurrences->paginate(
            $this->dayBound($request, 'from', $tenantId),
            // `to` é dia de calendário e por isso exclusivo na virada: a agenda
            // de 30/09 precisa do dia inteiro, e comparar `due_at` com a
            // meia-noite do dia 30 deixaria de fora o que dispara depois dela.
            $this->dayBound($request, 'to', $tenantId)?->addDay(),
            $this->optionalString($request->query('subject_type')),
            $this->optionalString($request->query('status')),
            $pagination,
        );

        return [
            'data' => $this->resource->collection($occurrences->getCollection(), $timezone),
            'meta' => $pagination->meta($occurrences->total(), $occurrences->lastPage()),
        ];
    }

    public function complete(Request $request, string $occurrenceId, ?string $subjectType = null): array
    {
        $tenantId = $this->tenantId($request);
        $occurrence = $this->find($tenantId, $occurrenceId, $subjectType);
        $timezone = $this->calendar->timezone($tenantId);

        if ($occurrence->status === JobSchedule::STATUS_COMPLETED) {
            return ['data' => $this->resource->make($occurrence, $timezone)];
        }

        if ($occurrence->isFinal()) {
            throw OccurrenceAlreadyFinalized::make($occurrence->status, 'complete');
        }

        $actor = (string) $request->user()->id;
        $at = CarbonImmutable::now('UTC');

        $changed = DB::transaction(function () use ($tenantId, $occurrence, $actor, $at): bool {
            // A atualização é condicional ao status de origem: a segunda
            // conclusão não tem linha para alterar, e é isso — e não uma
            // checagem antes — que fecha a corrida entre dois check-ins.
            if (! $this->occurrences->markCompleted($occurrence, $at, $actor)) {
                return false;
            }

            $this->trail->record($tenantId, $occurrence->id, ScheduleEvent::COMPLETED, $actor, [
                'scheduled_for' => $occurrence->scheduled_for->format('Y-m-d'),
            ], $at);

            return true;
        });

        if ($changed) {
            RecalculateNextOccurrence::dispatch(
                $tenantId,
                $occurrence->id,
                $occurrence->scheduled_for->format('Y-m-d'),
            )
                ->onQueue((string) config('scheduling.queues.occurrences'))
                ->afterCommit();
        }

        return ['data' => $this->resource->make(
            $this->occurrences->find($occurrenceId, $tenantId) ?? $occurrence,
            $timezone,
        )];
    }

    public function skip(Request $request, string $occurrenceId, ?string $subjectType = null): array
    {
        $tenantId = $this->tenantId($request);
        $occurrence = $this->find($tenantId, $occurrenceId, $subjectType);
        $timezone = $this->calendar->timezone($tenantId);

        if ($occurrence->status === JobSchedule::STATUS_SKIPPED) {
            return ['data' => $this->resource->make($occurrence, $timezone)];
        }

        if ($occurrence->isFinal()) {
            throw OccurrenceAlreadyFinalized::make($occurrence->status, 'skip');
        }

        $actor = (string) $request->user()->id;
        $at = CarbonImmutable::now('UTC');

        DB::transaction(function () use ($tenantId, $occurrence, $actor, $at): void {
            if (! $this->occurrences->markSkipped($occurrence)) {
                return;
            }

            $this->trail->record($tenantId, $occurrence->id, ScheduleEvent::SKIPPED, $actor, [
                'scheduled_for' => $occurrence->scheduled_for->format('Y-m-d'),
            ], $at);
        });

        // A dispensa não recalcula: pular é dizer que aquele dia não acontece,
        // e o ciclo continua na data que a regra já tinha.
        return ['data' => $this->resource->make(
            $this->occurrences->find($occurrenceId, $tenantId) ?? $occurrence,
            $timezone,
        )];
    }

    private function find(string $tenantId, string $occurrenceId, ?string $subjectType): JobSchedule
    {
        $occurrence = $this->occurrences->find($occurrenceId, $tenantId);

        if ($occurrence === null) {
            throw OccurrenceNotFound::make(['occurrence_id' => $occurrenceId]);
        }

        if ($subjectType !== null && $occurrence->triggerConfig?->subject_type !== $subjectType) {
            throw OccurrenceNotFound::make(['occurrence_id' => $occurrenceId, 'subject_type' => $subjectType]);
        }

        return $occurrence;
    }

    private function dayBound(Request $request, string $key, string $tenantId): ?CarbonImmutable
    {
        $value = $this->optionalString($request->query($key));

        return $value === null ? null : $this->calendar->day($value, $tenantId);
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
