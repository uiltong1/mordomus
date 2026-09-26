<?php

namespace Mordomus\Maintenance\Http\Presenters;

use Mordomus\Maintenance\Models\Room;

/**
 * Shape do cômodo.
 */
final class RoomPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function make(Room $room): array
    {
        return [
            'id' => $room->id,
            'tenant_id' => $room->tenant_id,
            'name' => $room->name,
            'icon' => $room->icon,
            'sort_order' => $room->sort_order,
            'archived' => $room->isArchived(),
            'archived_at' => $room->archived_at?->toIso8601String(),
            'created_at' => $room->created_at?->toIso8601String(),
            'updated_at' => $room->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<Room>  $rooms
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $rooms): array
    {
        return collect($rooms)
            ->map(fn (Room $room): array => $this->make($room))
            ->values()
            ->all();
    }
}
