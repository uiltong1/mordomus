<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Services;

use Illuminate\Http\Request;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Maintenance\Contracts\Repositories\AssetRepositoryInterface;
use Mordomus\Maintenance\Contracts\Repositories\RoomRepositoryInterface;
use Mordomus\Maintenance\Contracts\Services\AssetServiceInterface;
use Mordomus\Maintenance\Contracts\Services\TenantClockServiceInterface;
use Mordomus\Maintenance\Exceptions\RoomNotFound;
use Mordomus\Maintenance\Http\Resources\AssetResource;
use Mordomus\Maintenance\Models\Asset;
use Mordomus\Maintenance\Models\Room;

final class AssetService implements AssetServiceInterface
{
    public function __construct(
        private readonly AssetRepositoryInterface $assets,
        private readonly RoomRepositoryInterface $rooms,
        private readonly AssetResource $resource,
        private readonly TenantClockServiceInterface $clock,
    ) {}

    public function index(Request $request): array
    {
        $pagination = OffsetPagination::from($request);

        $assets = $this->assets->paginate(
            $request->filled('room_id') ? (string) $request->query('room_id') : null,
            $request->filled('category') ? (string) $request->query('category') : null,
            $request->boolean('include_archived'),
            $pagination,
        );

        $timezone = $this->clock->timezone($request);

        return [
            'data' => $this->resource->collection($assets->getCollection(), $timezone),
            'meta' => $pagination->meta($assets->total(), $assets->lastPage()),
        ];
    }

    public function show(Request $request, string $assetId): array
    {
        $timezone = $this->clock->timezone($request);

        return ['data' => $this->resource->make($this->find($request, $assetId), $timezone)];
    }

    public function store(Request $request): array
    {
        $room = $this->activeRoom($request);
        $timezone = $this->clock->timezone($request);

        $asset = $this->assets->create([
            'room_id' => $room->id,
            'name' => $request->string('name')->toString(),
            'category' => $request->input('category'),
            'brand' => $request->input('brand'),
            'model' => $request->input('model'),
            'acquired_at' => $this->clock->instant($request, 'acquired_at', $timezone),
            'warranty_until' => $this->clock->instant($request, 'warranty_until', $timezone),
            'metadata' => $request->input('metadata'),
        ]);

        return ['data' => $this->resource->make($asset, $timezone)];
    }

    public function update(Request $request, string $assetId): array
    {
        $asset = $this->find($request, $assetId);
        $timezone = $this->clock->timezone($request);

        $attributes = $request->only(['name', 'category', 'brand', 'model', 'metadata']);

        if ($request->has('room_id')) {
            $attributes['room_id'] = $this->activeRoom($request)->id;
        }

        if ($request->has('acquired_at')) {
            $attributes['acquired_at'] = $this->clock->instant($request, 'acquired_at', $timezone);
        }

        if ($request->has('warranty_until')) {
            $attributes['warranty_until'] = $this->clock->instant($request, 'warranty_until', $timezone);
        }

        $asset = $this->assets->change($asset, $attributes);

        if ($request->has('archived')) {
            $asset = $request->boolean('archived')
                ? $this->assets->archive($asset)
                : $this->assets->restore($asset);
        }

        return ['data' => $this->resource->make($asset, $timezone)];
    }

    public function destroy(Request $request, string $assetId): array
    {
        $asset = $this->assets->archive($this->find($request, $assetId));

        return [
            'data' => $this->resource->make($asset, $this->clock->timezone($request)),
            'archived' => true,
        ];
    }

    /**
     * O TenantGlobalScope já esconde cômodos de outra residência; o filtro
     * de arquivados impede ativo em cômodo morto.
     */
    private function activeRoom(Request $request): Room
    {
        $room = $this->rooms->findActive((string) $request->input('room_id'));

        if ($room === null) {
            throw RoomNotFound::make(['room_id' => $request->input('room_id')]);
        }

        return $room;
    }

    private function find(Request $request, string $assetId): Asset
    {
        return $this->assets->findOrFail($assetId, ActiveTenant::id($request));
    }
}
