<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Illuminate\Http\Request;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\BillRepositoryInterface;
use Mordomus\Financial\Contracts\Services\BillServiceInterface;
use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Http\Resources\BillResource;
use Mordomus\Financial\Models\Bill;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerConfigServiceInterface;
use Mordomus\Scheduling\Models\TriggerConfig;

final class BillService implements BillServiceInterface
{
    public function __construct(
        private readonly BillRepositoryInterface $bills,
        private readonly BillOccurrenceRepositoryInterface $occurrences,
        private readonly BillResource $resource,
        private readonly MoneyServiceInterface $money,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly TriggerConfigServiceInterface $triggers,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);
        $pagination = OffsetPagination::from($request);

        $bills = $this->bills->paginate(
            $this->optionalString($request->query('kind')),
            $request->has('is_active') ? $request->boolean('is_active') : null,
            $this->optionalString($request->query('category')),
            $pagination,
        );

        return [
            'data' => $this->resource->collection($bills->getCollection(), $timezone),
            'meta' => $pagination->meta($bills->total(), $bills->lastPage()),
        ];
    }

    public function show(Request $request, string $billId): array
    {
        $tenantId = $this->tenantId($request);

        return ['data' => $this->resource->make(
            $this->find($request, $billId),
            $this->calendar->timezone($tenantId),
        )];
    }

    public function store(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);

        $bill = $this->bills->create([
            'is_active' => $request->boolean('is_active', true),
            'currency' => $this->currency($request),
            ...$this->attributes($request),
            'created_by' => (string) $request->user()->id,
        ]);

        $this->syncSchedule($request, $bill, $tenantId);

        return ['data' => $this->resource->make($this->reload($bill, $tenantId), $timezone)];
    }

    public function update(Request $request, string $billId): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);
        $bill = $this->find($request, $billId);

        $bill = $this->bills->change($bill, $this->attributes($request));

        $this->syncSchedule($request, $bill, $tenantId);
        $this->dropUpcomingWhenDeactivated($request, $bill, $tenantId);

        return ['data' => $this->resource->make($this->reload($bill, $tenantId), $timezone)];
    }

    private function syncSchedule(Request $request, Bill $bill, string $tenantId): void
    {
        $cadence = $request->has('due_day');
        $stateChanged = $request->has('is_active');

        if (! $cadence && ! $stateChanged) {
            return;
        }

        if (! $bill->isActive() || ($cadence && $request->input('due_day') === null)) {
            $this->triggers->pauseForSubject($tenantId, TriggerConfig::SUBJECT_BILL, $bill->id);

            return;
        }

        if (! $cadence) {
            $this->triggers->resumeForSubject($tenantId, TriggerConfig::SUBJECT_BILL, $bill->id);

            return;
        }

        $attributes = [
            'title' => (string) $bill->name,
            'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
            'day_of_month' => $request->integer('due_day'),
            'is_active' => true,
        ];

        if ($request->has('advance_notice_days')) {
            $attributes['advance_notice_days'] = $request->integer('advance_notice_days');
        }

        $this->triggers->storeExclusiveForSubject($tenantId, TriggerConfig::SUBJECT_BILL, $bill->id, $attributes);
    }

    private function dropUpcomingWhenDeactivated(Request $request, Bill $bill, string $tenantId): void
    {
        if (! $request->has('is_active') || $request->boolean('is_active') || $bill->isActive()) {
            return;
        }

        $today = $this->calendar->day(now(), $tenantId)->format('Y-m-d');

        $this->occurrences->cancelUpcoming($bill->id, $today);
    }

    private function attributes(Request $request): array
    {
        $attributes = $request->only(['name', 'kind', 'category', 'is_active']);

        if ($request->has('currency')) {
            $attributes['currency'] = $this->currency($request);
        }

        if ($request->has('amount')) {
            $attributes['amount'] = $this->amount($request);
        }

        return $attributes;
    }

    private function currency(Request $request): string
    {
        return strtoupper($request->string('currency')->toString() ?: 'BRL');
    }

    private function amount(Request $request): ?string
    {
        $value = $request->input('amount');

        if ($value === null || $value === '') {
            return null;
        }

        return $this->money->fromCents($this->money->cents($request->string('amount')->toString()));
    }

    private function reload(Bill $bill, string $tenantId): Bill
    {
        return $this->bills->find($bill->id, $tenantId) ?? $bill;
    }

    private function find(Request $request, string $billId): Bill
    {
        return $this->bills->findOrFail($billId, $this->tenantId($request));
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
