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

/**
 * Consolidação mensal: quanto a casa gastou, quanto pagou e o que ficou em
 * aberto.
 *
 * A agregação é feita em memória sobre os vencimentos do mês em vez de no
 * banco: o recorte é de uma residência, são dezenas de linhas, e somar texto
 * decimal em PHP pelo `MoneyService` é o que garante que a soma das cotas
 * feche com o total (regra R5) sem depender do arredondamento do driver.
 */
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

    /**
     * @param  Collection<int, BillOccurrence>  $occurrences
     * @return array<string, mixed>
     */
    private function consolidate(Collection $occurrences, string $month, string $timezone): array
    {
        $byStatus = array_fill_keys(BillOccurrence::STATUSES, 0);
        $totals = ['due' => [], 'paid' => [], 'open' => [], 'overdue' => [], 'cancelled' => []];

        foreach ($occurrences as $occurrence) {
            $byStatus[$occurrence->status]++;

            // Cada estado tem o seu balde, e `due` soma todos: um vencimento
            // cancelado saiu da conta da casa, mas não deixa de ter sido um
            // lançamento do mês.
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

    /**
     * Recorte por categoria da conta, que é o eixo que o morador reconhece —
     * moradia, energia, transporte — e não o nome de cada conta.
     *
     * @param  Collection<int, BillOccurrence>  $occurrences
     * @return list<array<string, mixed>>
     */
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

    /** Mês pedido ou o mês corrente da residência, no fuso dela. */
    private function month(Request $request, string $timezone): string
    {
        $month = $request->string('month')->toString();

        return $month === ''
            ? CarbonImmutable::now($timezone)->format('Y-m')
            : $month;
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
