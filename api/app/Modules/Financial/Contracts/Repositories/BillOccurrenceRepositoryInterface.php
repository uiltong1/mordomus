<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Http\Pagination\OffsetPagination;

interface BillOccurrenceRepositoryInterface
{
    /**
     * @param  string|null  $from  dia inicial inclusivo (`Y-m-d`)
     * @param  string|null  $to  dia final inclusivo (`Y-m-d`)
     */
    public function paginate(
        ?string $from,
        ?string $to,
        ?string $billId,
        ?string $status,
        OffsetPagination $pagination,
    ): LengthAwarePaginator;

    public function findOrFail(string $billOccurrenceId, ?string $tenantId): BillOccurrence;

    public function find(string $billOccurrenceId, ?string $tenantId): ?BillOccurrence;

    /** Vencimento pela data, que é a chave de idempotência do consumidor. */
    public function findByDueDate(string $billId, string $dueDate): ?BillOccurrence;

    /** Vencimento que materializou a ocorrência do Scheduling. */
    public function findByScheduleId(string $scheduleId): ?BillOccurrence;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): BillOccurrence;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function change(BillOccurrence $occurrence, array $attributes): BillOccurrence;

    /**
     * Quitação condicional ao estado de origem: o pagamento repetido não tem
     * linha para alterar, e é isso — e não uma checagem antes — que fecha a
     * corrida entre dois cliques no botão de pago.
     */
    public function markPaid(BillOccurrence $occurrence, string $paidAt, string $userId, string $amount): bool;

    /** @return int vencimentos que entraram na condição de atraso */
    public function markOverdue(string $tenantId, string $before): int;

    /**
     * Cancela o que ainda não venceu de uma conta que deixou de ser devida.
     *
     * @param  string  $from  dia de corte inclusivo (`Y-m-d`)
     * @return int vencimentos cancelados
     */
    public function cancelUpcoming(string $billId, string $from): int;

    /** Vencimentos do período, para a consolidação mensal. @return Collection<int, BillOccurrence> */
    public function between(string $tenantId, string $from, string $to): Collection;
}
