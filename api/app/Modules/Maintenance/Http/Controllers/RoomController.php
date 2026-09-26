<?php

namespace Mordomus\Maintenance\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Maintenance\Http\Requests\ReorderRoomsRequest;
use Mordomus\Maintenance\Http\Requests\StoreRoomRequest;
use Mordomus\Maintenance\Http\Requests\UpdateRoomRequest;
use Mordomus\Maintenance\Models\Room;

/**
 * T2.1.2 — CRUD, ordenação e arquivamento lógico dos cômodos.
 *
 * Toda query passa pelo escopo global do tenant (R1); um id de outra
 * residência simplesmente não existe aqui e vira 404.
 */
class RoomController extends Controller
{
    /** GET /rooms — cômodos do tenant, na ordem persistida. */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);

        $rooms = $this->baseQuery($request)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $rooms->getCollection()
                ->map(fn (Room $room): array => $this->payload($room))
                ->values(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $rooms->total(),
                'last_page' => $rooms->lastPage(),
            ],
        ]);
    }

    /** GET /rooms/{room}. */
    public function show(Request $request, string $room): JsonResponse
    {
        return response()->json([
            'data' => $this->payload($this->findOrFail($room, $request)),
        ]);
    }

    /** POST /rooms. */
    public function store(StoreRoomRequest $request): JsonResponse
    {
        $sortOrder = $request->has('sort_order')
            ? $request->integer('sort_order')
            : $this->nextSortOrder();

        $room = Room::create([
            'name' => $request->string('name')->toString(),
            'icon' => $request->input('icon'),
            'sort_order' => $sortOrder,
        ]);

        return response()->json(['data' => $this->payload($room)], 201);
    }

    /** PATCH /rooms/{room} — inclui o arquivamento lógico. */
    public function update(UpdateRoomRequest $request, string $room): JsonResponse
    {
        $room = $this->findOrFail($room, $request);
        $room->fill($request->only(['name', 'icon', 'sort_order']));
        $room->save();

        if ($request->has('archived')) {
            $request->boolean('archived') ? $room->archive() : $room->restore();
            $room->refresh();
        }

        return response()->json(['data' => $this->payload($room)]);
    }

    /** DELETE /rooms/{room} — arquiva, nunca apaga (ADR-006). */
    public function destroy(Request $request, string $room): JsonResponse
    {
        $room = $this->findOrFail($room, $request);
        $room->archive();

        return response()->json(['data' => $this->payload($room), 'archived' => true]);
    }

    /** PUT /rooms/order — reordenação em lote (drag & drop). */
    public function order(ReorderRoomsRequest $request): JsonResponse
    {
        $given = collect($request->input('ids'))->sort()->values()->all();

        $expected = $this->activeRooms()->pluck('id')->sort()->values()->all();

        if ($given !== $expected) {
            return $this->error(
                $request,
                422,
                'validation_failed',
                'A lista de ids precisa ser exatamente os cômodos não arquivados da residência.',
                ['expected' => $expected, 'received' => $given],
            );
        }

        DB::transaction(function () use ($request): void {
            foreach ($request->input('ids') as $index => $id) {
                Room::query()->where('id', $id)->update(['sort_order' => $index]);
            }
        });

        $data = $this->activeRooms()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Room $room): array => $this->payload($room))
            ->values();

        return response()->json(['data' => $data]);
    }

    /** Só os não arquivados — a reordenação só mexe na lista viva. */
    private function activeRooms(): Builder
    {
        return Room::query()->whereNull('archived_at');
    }

    /** Cômodos do tenant ativo; `include_archived` traz os arquivados. */
    private function baseQuery(Request $request): Builder
    {
        $query = Room::query();

        if ($request->boolean('include_archived')) {
            return $query;
        }

        return $query->whereNull('archived_at');
    }

    private function findOrFail(string $id, Request $request): Room
    {
        return Room::query()
            ->where('id', $id)
            ->where('tenant_id', (string) $this->activeTenantId($request))
            ->firstOrFail();
    }

    /** Próximo `sort_order` entre os cômodos vivos (sem buracos após arquivar). */
    private function nextSortOrder(): int
    {
        return ((int) $this->activeRooms()->max('sort_order')) + 1;
    }

    /** @return array<string, mixed> */
    private function payload(Room $room): array
    {
        return [
            'id' => $room->id,
            'tenant_id' => $room->tenant_id,
            'name' => $room->name,
            'icon' => $room->icon,
            'sort_order' => $room->sort_order,
            'archived' => $room->isArchived(),
            'archived_at' => optional($room->archived_at)->toIso8601String(),
            'created_at' => optional($room->created_at)->toIso8601String(),
            'updated_at' => optional($room->updated_at)->toIso8601String(),
        ];
    }
}
