<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mordomus\Http\Exceptions\ResourceNotFound;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Maintenance\Contracts\Repositories\RoomRepositoryInterface;
use Mordomus\Maintenance\Models\Room;

final class RoomRepository implements RoomRepositoryInterface
{
    public function paginate(bool $includeArchived, OffsetPagination $pagination): LengthAwarePaginator
    {
        $query = Room::query();

        if (! $includeArchived) {
            $query->active();
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);
    }

    public function findOrFail(string $roomId, ?string $tenantId): Room
    {
        if ($tenantId === null) {
            throw ResourceNotFound::make();
        }

        $room = Room::query()
            ->where('id', $roomId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($room === null) {
            throw ResourceNotFound::make();
        }

        return $room;
    }

    public function findActive(string $roomId): ?Room
    {
        return Room::query()->active()->find($roomId);
    }

    public function create(array $attributes): Room
    {
        return Room::create($attributes);
    }

    public function change(Room $room, array $attributes): Room
    {
        $room->fill($attributes);
        $room->save();

        return $room;
    }

    public function archive(Room $room): Room
    {
        $room->archive();
        $room->refresh();

        return $room;
    }

    public function restore(Room $room): Room
    {
        $room->restore();
        $room->refresh();

        return $room;
    }

    public function activeIds(): array
    {
        return Room::query()
            ->active()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
    }

    public function ordered(): Collection
    {
        return Room::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

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
