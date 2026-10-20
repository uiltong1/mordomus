<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\BillRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\PaymentRecordRepositoryInterface;
use Mordomus\Financial\Contracts\Services\BillOccurrenceServiceInterface;
use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitResultServiceInterface;
use Mordomus\Financial\Events\EventName;
use Mordomus\Financial\Exceptions\BillOccurrenceNotPayable;
use Mordomus\Financial\Exceptions\DuplicateBillOccurrence;
use Mordomus\Financial\Http\Resources\BillOccurrenceResource;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;

final class BillOccurrenceService implements BillOccurrenceServiceInterface
{
    public function __construct(
        private readonly BillRepositoryInterface $bills,
        private readonly BillOccurrenceRepositoryInterface $occurrences,
        private readonly PaymentRecordRepositoryInterface $payments,
        private readonly SplitResultServiceInterface $splits,
        private readonly BillOccurrenceResource $resource,
        private readonly MoneyServiceInterface $money,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly EventPublisherServiceInterface $publisher,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);
        $pagination = OffsetPagination::from($request);
        [$from, $to] = $this->range($request, $tenantId);

        $occurrences = $this->occurrences->paginate(
            $from,
            $to,
            $this->optionalString($request->query('bill_id')),
            $this->optionalString($request->query('status')),
            $pagination,
        );

        return [
            'data' => $this->resource->collection($occurrences->getCollection(), $timezone),
            'meta' => $pagination->meta($occurrences->total(), $occurrences->lastPage()),
        ];
    }

    public function store(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);
        $bill = $this->bills->findOrFail($request->string('bill_id')->toString(), $tenantId);
        $dueDate = $this->day($request->string('due_date')->toString(), $tenantId)->format('Y-m-d');

        if ($this->occurrences->findByDueDate($bill->id, $dueDate) !== null) {
            throw DuplicateBillOccurrence::make($bill->id, $dueDate);
        }

        $occurrence = $this->occurrences->create([
            'tenant_id' => $tenantId,
            'bill_id' => $bill->id,

            'schedule_id' => null,
            'due_date' => $dueDate,
            'amount' => $this->amount($request, $bill->amount),
        ]);

        $this->splits->compute($occurrence);

        return ['data' => $this->resource->make($occurrence, $timezone)];
    }

    public function pay(Request $request, string $billOccurrenceId): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);
        $occurrence = $this->find($request, $billOccurrenceId);

        if ($occurrence->isPaid()) {
            return ['data' => $this->resource->make($occurrence, $timezone)];
        }

        if (! $occurrence->isPayable()) {
            throw BillOccurrenceNotPayable::make($occurrence->status);
        }

        $actor = (string) $request->user()->id;
        $amount = $this->amount($request, $occurrence->amount);
        $paidAt = $this->paidAt($request, $tenantId);
        $method = $request->string('method')->toString();
        $receiptUrl = $this->optionalString($request->input('receipt_url'));

        $changed = DB::transaction(function () use ($tenantId, $occurrence, $actor, $amount, $paidAt, $method, $receiptUrl): bool {

            if (! $this->occurrences->markPaid($occurrence, $paidAt->toIso8601String(), $actor, $amount)) {
                return false;
            }

            $this->payments->create([
                'tenant_id' => $tenantId,
                'bill_occurrence_id' => $occurrence->id,
                'user_id' => $actor,
                'amount' => $amount,
                'method' => $method,
                'paid_at' => $paidAt,
                'receipt_url' => $receiptUrl,
            ]);

            return true;
        });

        if (! $changed) {
            return ['data' => $this->resource->make($this->reload($occurrence, $tenantId), $timezone)];
        }

        $paid = $this->reload($occurrence, $tenantId);

        $this->splits->compute($paid);

        $this->publisher->publish(EventName::BILL_PAID, $tenantId, [
            'tenant_id' => $tenantId,
            'bill_occurrence_id' => $occurrence->id,
            'bill_id' => $occurrence->bill_id,
            'paid_by' => $actor,
            'amount' => $amount,
            'method' => $method,
            'due_date' => $occurrence->dueDate(),
            'paid_at' => $paidAt->toIso8601String(),
        ]);

        return ['data' => $this->resource->make($paid, $timezone)];
    }

    private function range(Request $request, string $tenantId): array
    {
        $month = $this->optionalString($request->query('month'));

        if ($month !== null) {
            $first = $this->day($month.'-01', $tenantId);

            return [$first->format('Y-m-d'), $first->endOfMonth()->format('Y-m-d')];
        }

        $from = $this->optionalString($request->query('from'));
        $to = $this->optionalString($request->query('to'));

        return [
            $from === null ? null : $this->day($from, $tenantId)->format('Y-m-d'),
            $to === null ? null : $this->day($to, $tenantId)->format('Y-m-d'),
        ];
    }

    private function amount(Request $request, ?string $expected): string
    {
        $value = $request->input('amount');

        if ($value === null || $value === '') {
            return $this->money->fromCents($this->money->cents($expected));
        }

        return $this->money->fromCents($this->money->cents($request->string('amount')->toString()));
    }

    private function paidAt(Request $request, string $tenantId): CarbonImmutable
    {
        $paidAt = $this->optionalString($request->input('paid_at'));

        return $paidAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($paidAt, $this->calendar->timezone($tenantId))->utc();
    }

    private function day(string $calendarDay, string $tenantId): CarbonImmutable
    {
        return $this->calendar->day($calendarDay, $tenantId);
    }

    private function reload(BillOccurrence $occurrence, string $tenantId): BillOccurrence
    {
        return $this->occurrences->find($occurrence->id, $tenantId) ?? $occurrence;
    }

    private function find(Request $request, string $billOccurrenceId): BillOccurrence
    {
        return $this->occurrences->findOrFail($billOccurrenceId, $this->tenantId($request));
    }

    private function tenantId(Request $request): string
    {

        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
