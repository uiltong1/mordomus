<?php

declare(strict_types=1);

namespace Mordomus\Financial\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Exceptions\BillOccurrenceNotFound;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Http\Exceptions\ResourceNotFound;
use Mordomus\Http\Pagination\OffsetPagination;

final class BillOccurrenceRepository implements BillOccurrenceRepositoryInterface
{
    public function paginate(
        ?string $from,
        ?string $to,
        ?string $billId,
        ?string $status,
        OffsetPagination $pagination,
    ): LengthAwarePaginator {
        return $this->query()
            ->when($from !== null, fn ($query) => $query->where('due_date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('due_date', '<=', $to))
            ->when($billId !== null, fn ($query) => $query->where('bill_id', $billId))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);
    }

    public function findOrFail(string $billOccurrenceId, ?string $tenantId): BillOccurrence
    {
        if ($tenantId === null) {
            throw ResourceNotFound::make();
        }

        return $this->find($billOccurrenceId, $tenantId) ?? throw BillOccurrenceNotFound::make([
            'bill_occurrence_id' => $billOccurrenceId,
        ]);
    }

    public function find(string $billOccurrenceId, ?string $tenantId): ?BillOccurrence
    {
        return $this->query()
            ->where('id', $billOccurrenceId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    public function findByDueDate(string $billId, string $dueDate): ?BillOccurrence
    {
        return $this->query()->where('bill_id', $billId)->where('due_date', $dueDate)->first();
    }

    public function findByScheduleId(string $scheduleId): ?BillOccurrence
    {
        return $this->query()->where('schedule_id', $scheduleId)->first();
    }

    public function create(array $attributes): BillOccurrence
    {
        // `refresh()` porque os padrões do banco (`status = open`) não voltam
        // no model que o `create` devolve, e a resposta da API é montada
        // direto desse model.
        return BillOccurrence::create($attributes)->refresh();
    }

    public function change(BillOccurrence $occurrence, array $attributes): BillOccurrence
    {
        $occurrence->fill($attributes);
        $occurrence->save();

        return $occurrence;
    }

    public function markPaid(BillOccurrence $occurrence, string $paidAt, string $userId, string $amount): bool
    {
        return BillOccurrence::query()
            ->whereKey($occurrence->id)
            ->whereIn('status', BillOccurrence::PAYABLE_STATUSES)
            ->update([
                'status' => BillOccurrence::STATUS_PAID,
                'paid_at' => $paidAt,
                'paid_by' => $userId,
                'amount' => $amount,
            ]) === 1;
    }

    public function markOverdue(string $tenantId, string $before): int
    {
        return BillOccurrence::query()
            ->where('tenant_id', $tenantId)
            ->where('status', BillOccurrence::STATUS_OPEN)
            ->where('due_date', '<', $before)
            ->update(['status' => BillOccurrence::STATUS_OVERDUE]);
    }

    /**
     * Vencimentos que ainda nem vencer e que a conta deixou de dever.
     *
     * O corte em `due_date >= $from` é o que separa "deixou de ser dívida" de
     * "apagado": o que já venceu continua em aberto até ser pago.
     */
    public function cancelUpcoming(string $billId, string $from): int
    {
        return BillOccurrence::query()
            ->where('bill_id', $billId)
            ->where('status', BillOccurrence::STATUS_OPEN)
            ->where('due_date', '>=', $from)
            ->update(['status' => BillOccurrence::STATUS_CANCELLED]);
    }

    public function between(string $tenantId, string $from, string $to): Collection
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('due_date', [$from, $to])
            ->get();
    }

    /**
     * Base das leituras, com a conta e as baixas já anexadas.
     *
     * O histórico de pagamento faz parte do vencimento: quem abre a lista de
     * contas precisa saber o que foi pago sem uma segunda chamada por linha.
     *
     * @return Builder<BillOccurrence>
     */
    private function query(): Builder
    {
        return BillOccurrence::query()->with(['bill', 'payments']);
    }
}
