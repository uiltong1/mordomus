<?php

declare(strict_types=1);

namespace Mordomus\Financial\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Mordomus\Financial\Contracts\Repositories\BillRepositoryInterface;
use Mordomus\Financial\Exceptions\BillNotFound;
use Mordomus\Financial\Models\Bill;
use Mordomus\Http\Exceptions\ResourceNotFound;
use Mordomus\Http\Pagination\OffsetPagination;

final class BillRepository implements BillRepositoryInterface
{
    public function paginate(
        ?string $kind,
        ?bool $isActive,
        ?string $category,
        OffsetPagination $pagination,
    ): LengthAwarePaginator {
        return Bill::query()
            ->with('schedules')
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);
    }

    public function findOrFail(string $billId, ?string $tenantId): Bill
    {
        if ($tenantId === null) {
            throw ResourceNotFound::make();
        }

        return $this->find($billId, $tenantId) ?? throw BillNotFound::make(['bill_id' => $billId]);
    }

    public function find(string $billId, ?string $tenantId): ?Bill
    {
        return Bill::query()
            ->with('schedules')
            ->where('id', $billId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    public function create(array $attributes): Bill
    {
        // `refresh()` para a resposta já sair com os padrões do banco.
        return Bill::create($attributes)->refresh();
    }

    public function change(Bill $bill, array $attributes): Bill
    {
        $bill->fill($attributes);
        $bill->save();

        return $bill;
    }
}
