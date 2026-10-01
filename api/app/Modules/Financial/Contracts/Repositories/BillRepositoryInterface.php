<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Mordomus\Financial\Models\Bill;
use Mordomus\Http\Pagination\OffsetPagination;

interface BillRepositoryInterface
{
    public function paginate(
        ?string $kind,
        ?bool $isActive,
        ?string $category,
        OffsetPagination $pagination,
    ): LengthAwarePaginator;

    public function findOrFail(string $billId, ?string $tenantId): Bill;

    public function find(string $billId, ?string $tenantId): ?Bill;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Bill;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function change(Bill $bill, array $attributes): Bill;
}
