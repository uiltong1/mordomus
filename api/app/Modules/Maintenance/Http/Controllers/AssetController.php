<?php

namespace Mordomus\Maintenance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Maintenance\Http\Requests\StoreAssetRequest;
use Mordomus\Maintenance\Http\Requests\UpdateAssetRequest;
use Mordomus\Maintenance\Models\Asset;
use Mordomus\Maintenance\Models\Room;

/**
 * T2.2 — inventário de ativos: CRUD, transferência entre cômodos,
 * filtros (`?room_id=&category=`) e paginação offset.
 *
 * Datas entram e saem no fuso do tenant (AC 2); o `room_id` é resolvido
 * sempre dentro do escopo de residência, então um cômodo de outro tenant
 * simplesmente não existe — vira `404 room_not_found`.
 */
class AssetController extends Controller
{
    /** GET /assets — inventário paginado, com filtros de cômodo e categoria. */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);

        $query = Asset::query();

        if ($request->filled('room_id')) {
            $query->where('room_id', (string) $request->query('room_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        $assets = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $timezone = $this->tenantTimezone($request);

        return response()->json([
            'data' => $assets->getCollection()
                ->map(fn (Asset $asset): array => $this->payload($asset, $timezone))
                ->values(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $assets->total(),
                'last_page' => $assets->lastPage(),
            ],
        ]);
    }

    /** GET /assets/{asset}. */
    public function show(Request $request, string $asset): JsonResponse
    {
        return response()->json([
            'data' => $this->payload($this->findOrFail($asset, $request), $this->tenantTimezone($request)),
        ]);
    }

    /** POST /assets. */
    public function store(StoreAssetRequest $request): JsonResponse
    {
        $room = $this->activeRoom((string) $request->input('room_id'));

        if ($room === null) {
            return $this->roomNotFound($request);
        }

        $timezone = $this->tenantTimezone($request);

        $asset = Asset::create([
            'room_id' => $room->id,
            'name' => $request->string('name')->toString(),
            'category' => $request->input('category'),
            'brand' => $request->input('brand'),
            'model' => $request->input('model'),
            'acquired_at' => $this->instant($request, 'acquired_at', $timezone),
            'warranty_until' => $this->instant($request, 'warranty_until', $timezone),
            'metadata' => $request->input('metadata'),
        ]);

        return response()->json(['data' => $this->payload($asset, $timezone)], 201);
    }

    /** PATCH /assets/{asset} — `room_id` presente = transferência de cômodo. */
    public function update(UpdateAssetRequest $request, string $asset): JsonResponse
    {
        $asset = $this->findOrFail($asset, $request);
        $timezone = $this->tenantTimezone($request);

        $attributes = $request->only(['name', 'category', 'brand', 'model', 'metadata']);

        if ($request->has('room_id')) {
            $room = $this->activeRoom((string) $request->input('room_id'));

            if ($room === null) {
                return $this->roomNotFound($request);
            }

            $attributes['room_id'] = $room->id;
        }

        if ($request->has('acquired_at')) {
            $attributes['acquired_at'] = $this->instant($request, 'acquired_at', $timezone);
        }

        if ($request->has('warranty_until')) {
            $attributes['warranty_until'] = $this->instant($request, 'warranty_until', $timezone);
        }

        $asset->fill($attributes);
        $asset->save();

        if ($request->has('archived')) {
            $request->boolean('archived') ? $asset->archive() : $asset->restore();
            $asset->refresh();
        }

        return response()->json(['data' => $this->payload($asset, $timezone)]);
    }

    /** DELETE /assets/{asset} — arquiva, nunca apaga (ADR-006). */
    public function destroy(Request $request, string $asset): JsonResponse
    {
        $asset = $this->findOrFail($asset, $request);
        $asset->archive();

        return response()->json([
            'data' => $this->payload($asset, $this->tenantTimezone($request)),
            'archived' => true,
        ]);
    }

    /**
     * Cômodo vivo da residência ativa. O `TenantGlobalScope` já esconde os de
     * outra residência; o filtro de arquivados impede ativo em cômodo morto.
     */
    private function activeRoom(string $roomId): ?Room
    {
        return Room::query()->whereNull('archived_at')->find($roomId);
    }

    private function roomNotFound(Request $request): JsonResponse
    {
        return $this->error(
            $request,
            404,
            'room_not_found',
            'Cômodo não encontrado ou arquivado na residência ativa.',
            ['room_id' => $request->input('room_id')],
        );
    }

    private function findOrFail(string $id, Request $request): Asset
    {
        return Asset::query()
            ->where('id', $id)
            ->where('tenant_id', (string) $this->activeTenantId($request))
            ->firstOrFail();
    }

    /**
     * Fuso da residência ativa (`tenants.timezone`) — leitura livre do módulo
     * Identity, escrita continua sendo só do dono da tabela (TECHSPEC §3).
     *
     * Sem memoização: o Laravel guarda a instância do controller no objeto
     * `Route`, então um campo memorizado sobreviveria entre requisições no
     * mesmo processo (testes, Octane) e serviria o fuso da residência errada.
     */
    private function tenantTimezone(Request $request): string
    {
        $tenant = Tenant::query()->find($this->activeTenantId($request));

        return $tenant?->timezone ?: 'America/Sao_Paulo';
    }

    /** Data lida como calendário no fuso do tenant e persistida como instante. */
    private function instant(Request $request, string $field, string $timezone): ?Carbon
    {
        $value = $request->input($field);

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, $timezone)->utc();
    }

    /** @return array<string, mixed> */
    private function payload(Asset $asset, string $timezone): array
    {
        return [
            'id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'room_id' => $asset->room_id,
            'name' => $asset->name,
            'category' => $asset->category,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'acquired_at' => $this->formatInTimezone($asset->acquired_at, $timezone),
            'warranty_until' => $this->formatInTimezone($asset->warranty_until, $timezone),
            'metadata' => $asset->metadata,
            'archived' => $asset->isArchived(),
            'archived_at' => $this->formatInTimezone($asset->archived_at, $timezone),
            'created_at' => optional($asset->created_at)->toIso8601String(),
            'updated_at' => optional($asset->updated_at)->toIso8601String(),
        ];
    }

    private function formatInTimezone(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->timezone($timezone)->toIso8601String();
    }
}
