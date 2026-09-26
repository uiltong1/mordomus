<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Maintenance\Models\Room;

interface RoomRepositoryInterface
{
    public function paginate(bool $includeArchived, OffsetPagination $pagination): LengthAwarePaginator;

    public function findOrFail(string $roomId, ?string $tenantId): Room;

    public function findActive(string $roomId): ?Room;

    public function create(array $attributes): Room;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function change(Room $room, array $attributes): Room;

    public function archive(Room $room): Room;

    public function restore(Room $room): Room;

    /** @return list<string> */
    public function activeIds(): array;

    /** @return Collection<int, Room> */
    public function ordered(): Collection;

    /**
     * @param  list<string>  $ids
     */
    public function reorder(array $ids): void;

    public function nextSortOrder(): int;
}
