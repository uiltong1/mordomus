<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Contracts\Services\BillSummaryServiceInterface;
use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Http\Resources\BillSummaryResource;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;

final class BillSummaryService implements BillSummaryServiceInterface
{
    public function __construct(
        private readonly BillOccurrenceRepositoryInterface $occurrences,
        private readonly BillSummaryResource $resource,
        private readonly MoneyServiceInterface $money,
        private readonly TenantCalendarServiceInterface $calendar,
    ) {}

    public function summary(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);

        $month = $this->month($request, $timezone);
        $first = $this->calendar->day($month.'-01', $tenantId);
        $from = $first->format('Y-m-d');
        $to = $first->endOfMonth()->format('Y-m-d');

        $occurrences = $this->occurrences->between($tenantId, $from, $to);

        return ['data' => $this->resource->make($this->consolidate($occurrences, $month, $timezone))];
    }

    private function consolidate(Collection $occurrences, string $month, string $timezone): array
    {
        $byStatus = array_fill_keys(BillOccurrence::STATUSES, 0);
        $totals = ['due' => [], 'paid' => [], 'open' => [], 'overdue' => [], 'cancelled' => []];

        foreach ($occurrences as $occurrence) {
            $byStatus[$occurrence->status]++;

            $totals['due'][] = $occurrence->amount;
            $totals[$occurrence->status][] = $occurrence->amount;
        }

        return [
            'month' => $month,
            'timezone' => $timezone,
            'totals' => [
                'due' => $this->money->sum($totals['due']),
                'paid' => $this->money->sum($totals['paid']),
                'open' => $this->money->sum($totals['open']),
                'overdue' => $this->money->sum($totals['overdue']),
                'cancelled' => $this->money->sum($totals['cancelled']),
            ],
            'counts' => [
                'occurrences' => $occurrences->count(),
                'paid' => $byStatus[BillOccurrence::STATUS_PAID],
                'open' => $byStatus[BillOccurrence::STATUS_OPEN],
                'overdue' => $byStatus[BillOccurrence::STATUS_OVERDUE],
                'cancelled' => $byStatus[BillOccurrence::STATUS_CANCELLED],
            ],
            'by_status' => $byStatus,
            'by_category' => $this->byCategory($occurrences),
        ];
    }

    private function byCategory(Collection $occurrences): array
    {
        $groups = [];

        foreach ($occurrences as $occurrence) {
            $category = $occurrence->bill?->category;
            $key = $category ?? 'sem_categoria';

            $groups[$key] ??= ['category' => $category, 'occurrences' => 0, 'due' => [], 'paid' => [], 'open' => []];

            $groups[$key]['occurrences']++;
            $groups[$key]['due'][] = $occurrence->amount;

            if ($occurrence->isPaid()) {
                $groups[$key]['paid'][] = $occurrence->amount;
            }

            if ($occurrence->isPayable()) {
                $groups[$key]['open'][] = $occurrence->amount;
            }
        }

        ksort($groups);

        return array_map(fn (array $group): array => [
            'category' => $group['category'],
            'occurrences' => $group['occurrences'],
            'due' => $this->money->sum($group['due']),
            'paid' => $this->money->sum($group['paid']),
            'open' => $this->money->sum($group['open']),
        ], array_values($groups));
    }

    private function month(Request $request, string $timezone): string
    {
        $month = $request->string('month')->toString();

        return $month === ''
            ? CarbonImmutable::now($timezone)->format('Y-m')
            : $month;
    }

    private function tenantId(Request $request): string
    {

        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
