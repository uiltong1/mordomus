<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Services;

use Illuminate\Http\Request;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Maintenance\Contracts\Repositories\RoomRepositoryInterface;
use Mordomus\Maintenance\Contracts\Services\RoomServiceInterface;
use Mordomus\Maintenance\Exceptions\ReorderMismatch;
use Mordomus\Maintenance\Http\Resources\RoomResource;
use Mordomus\Maintenance\Models\Room;

final class RoomService implements RoomServiceInterface
{
    public function __construct(
        private readonly RoomRepositoryInterface $rooms,
        private readonly RoomResource $resource,
    ) {}

    public function index(Request $request): array
    {
        $pagination = OffsetPagination::from($request);

        $rooms = $this->rooms->paginate($request->boolean('include_archived'), $pagination);

        return [
            'data' => $this->resource->collection($rooms->getCollection()),
            'meta' => $pagination->meta($rooms->total(), $rooms->lastPage()),
        ];
    }

    public function show(Request $request, string $roomId): array
    {
        return ['data' => $this->resource->make($this->find($request, $roomId))];
    }

    public function store(Request $request): array
    {
        $room = $this->rooms->create([
            'name' => $request->string('name')->toString(),
            'icon' => $request->input('icon'),
            'sort_order' => $request->has('sort_order')
                ? $request->integer('sort_order')
                : $this->rooms->nextSortOrder(),
        ]);

        return ['data' => $this->resource->make($room)];
    }

    public function update(Request $request, string $roomId): array
    {
        $room = $this->rooms->change($this->find($request, $roomId), $request->only(['name', 'icon', 'sort_order']));

        if ($request->has('archived')) {
            $room = $request->boolean('archived')
                ? $this->rooms->archive($room)
                : $this->rooms->restore($room);
        }

        return ['data' => $this->resource->make($room)];
    }

    public function destroy(Request $request, string $roomId): array
    {
        $room = $this->rooms->archive($this->find($request, $roomId));

        return ['data' => $this->resource->make($room), 'archived' => true];
    }

    public function order(Request $request): array
    {
        $received = collect($request->input('ids'))->sort()->values()->all();
        $expected = $this->rooms->activeIds();

        if ($received !== $expected) {
            throw ReorderMismatch::make($expected, $received);
        }

        $this->rooms->reorder($request->input('ids'));

        return ['data' => $this->resource->collection($this->rooms->ordered())];
    }

    private function find(Request $request, string $roomId): Room
    {
        return $this->rooms->findOrFail($roomId, ActiveTenant::id($request));
    }
}
