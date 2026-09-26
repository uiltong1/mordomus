<?php

namespace Mordomus\Maintenance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mordomus\Maintenance\Models\Room;

/**
 * Ordem dos cômodos: a lista viva é a referência da reordenação em lote e
 * também de onde sai o próximo `sort_order`.
 */
final class RoomOrder
{
    /**
     * @return list<string>
     */
    public function activeIds(): array
    {
        return Room::query()
            ->active()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Room>
     */
    public function ordered(): Collection
    {
        return Room::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>  $ids
     */
    public function reorder(array $ids): void
    {
        DB::transaction(function () use ($ids): void {
            foreach ($ids as $index => $id) {
                Room::query()->where('id', $id)->update(['sort_order' => $index]);
            }
        });
    }

    public function nextSortOrder(): int
    {
        return ((int) Room::query()->active()->max('sort_order')) + 1;
    }
}
