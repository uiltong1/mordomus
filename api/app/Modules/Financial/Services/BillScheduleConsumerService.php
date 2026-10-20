<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\BillRepositoryInterface;
use Mordomus\Financial\Contracts\Services\BillScheduleConsumerServiceInterface;
use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitResultServiceInterface;
use Mordomus\Financial\Events\EventName;
use Mordomus\Financial\Models\Bill;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Scheduling\Contracts\Repositories\JobScheduleRepositoryInterface;
use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Models\TriggerConfig;

final class BillScheduleConsumerService implements BillScheduleConsumerServiceInterface
{
    private const AMOUNT_UNKNOWN = '0.00';

    public function __construct(
        private readonly BillRepositoryInterface $bills,
        private readonly BillOccurrenceRepositoryInterface $occurrences,
        private readonly JobScheduleRepositoryInterface $schedules,
        private readonly SplitResultServiceInterface $splits,
        private readonly MoneyServiceInterface $money,
        private readonly EventPublisherServiceInterface $publisher,
    ) {}

    public function onOccurrenceCreated(string $tenantId, array $payload): void
    {
        $bill = $this->bills->find($this->string($payload, 'subject_id'), $tenantId);

        if ($bill === null) {

            Log::warning('financial.bill_not_found_for_occurrence', [
                'tenant_id' => $tenantId,
                'bill_id' => $this->string($payload, 'subject_id'),
                'schedule_id' => $this->string($payload, 'schedule_id'),
            ]);

            return;
        }

        $this->materialize($tenantId, $bill, [
            'schedule_id' => $this->string($payload, 'schedule_id'),
            'due_date' => $this->string($payload, 'scheduled_for'),
        ]);
    }

    public function projectDueDates(string $tenantId): int
    {
        $created = 0;

        foreach ($this->schedules->forSubject($tenantId, TriggerConfig::SUBJECT_BILL) as $occurrence) {
            $bill = $this->bills->find((string) $occurrence->triggerConfig?->subjectId(), $tenantId);

            if ($bill === null) {
                continue;
            }

            $created += $this->materialize($tenantId, $bill, [
                'schedule_id' => (string) $occurrence->id,
                'due_date' => CarbonImmutable::parse($occurrence->scheduled_for)->format('Y-m-d'),
            ]) ? 1 : 0;
        }

        return $created;
    }

    private function materialize(string $tenantId, Bill $bill, array $payload): bool
    {
        $existing = $this->occurrences->findByDueDate($bill->id, $payload['due_date']);

        if ($existing !== null) {
            $this->reconcile($existing, $payload['schedule_id'], $this->amount($bill));

            return false;
        }

        $occurrence = $this->occurrences->create([
            'tenant_id' => $tenantId,
            'bill_id' => $bill->id,
            'schedule_id' => $payload['schedule_id'],
            'due_date' => $payload['due_date'],
            'amount' => $this->amount($bill),
        ]);

        $this->splits->compute($occurrence);

        return true;
    }

    public function onDue(string $tenantId, array $payload): void
    {
        $occurrence = $this->occurrences->findByScheduleId($this->string($payload, 'schedule_id'));

        if ($occurrence === null) {
            Log::warning('financial.bill_occurrence_not_found_for_notice', [
                'tenant_id' => $tenantId,
                'schedule_id' => $this->string($payload, 'schedule_id'),
                'kind' => $this->string($payload, 'kind'),
            ]);

            return;
        }

        $this->publisher->publish(EventName::BILL_DUE, $tenantId, [
            'tenant_id' => $tenantId,
            'bill_occurrence_id' => $occurrence->id,
            'bill_id' => $occurrence->bill_id,
            'due_date' => $occurrence->dueDate(),
            'amount' => $occurrence->amount,
            'kind' => $this->string($payload, 'kind'),
            'due_at' => $this->string($payload, 'due_at'),
            'dedupe_key' => $this->string($payload, 'dedupe_key'),
        ]);
    }

    public function markOverdue(string $tenantId, string $today): int
    {
        $marked = $this->occurrences->markOverdue($tenantId, $today);

        if ($marked > 0) {
            Log::info('financial.bill_occurrences_overdue', [
                'tenant_id' => $tenantId,
                'bill_occurrences' => $marked,
                'before' => $today,
            ]);
        }

        return $marked;
    }

    private function reconcile(BillOccurrence $occurrence, string $scheduleId, string $amount): void
    {
        $attributes = array_filter([
            'schedule_id' => $occurrence->isFromSchedule() ? null : $scheduleId,
            'amount' => $occurrence->isPayable() ? $amount : null,
        ], fn (mixed $value): bool => $value !== null);

        if ($attributes === []) {
            return;
        }

        $occurrence = $this->occurrences->change($occurrence, $attributes);

        $this->splits->compute($occurrence);
    }

    private function amount(Bill $bill): string
    {
        return $bill->amount === null
            ? self::AMOUNT_UNKNOWN
            : $this->money->fromCents($this->money->cents($bill->amount));
    }

    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
