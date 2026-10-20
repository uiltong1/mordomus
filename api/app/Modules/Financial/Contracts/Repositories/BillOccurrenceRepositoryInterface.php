<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Http\Pagination\OffsetPagination;

interface BillOccurrenceRepositoryInterface
{
    public function paginate(
        ?string $from,
        ?string $to,
        ?string $billId,
        ?string $status,
        OffsetPagination $pagination,
    ): LengthAwarePaginator;

    public function findOrFail(string $billOccurrenceId, ?string $tenantId): BillOccurrence;

    public function find(string $billOccurrenceId, ?string $tenantId): ?BillOccurrence;

    public function findByDueDate(string $billId, string $dueDate): ?BillOccurrence;

    public function findByScheduleId(string $scheduleId): ?BillOccurrence;

    public function create(array $attributes): BillOccurrence;

    public function change(BillOccurrence $occurrence, array $attributes): BillOccurrence;

    public function markPaid(BillOccurrence $occurrence, string $paidAt, string $userId, string $amount): bool;

    public function markOverdue(string $tenantId, string $before): int;

    public function cancelUpcoming(string $billId, string $from): int;

    public function between(string $tenantId, string $from, string $to): Collection;

    public function open(string $tenantId, ?string $billId = null): Collection;
}
