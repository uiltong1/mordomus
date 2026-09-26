<?php

namespace Mordomus\Maintenance\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Maintenance\Models\Asset;

/**
 * Shape do ativo, com datas no fuso da residência.
 */
final class AssetResource
{
    /**
     * @return array<string, mixed>
     */
    public function make(Asset $asset, string $timezone): array
    {
        return [
            'id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'room_id' => $asset->room_id,
            'name' => $asset->name,
            'category' => $asset->category,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'acquired_at' => $this->format($asset->acquired_at, $timezone),
            'warranty_until' => $this->format($asset->warranty_until, $timezone),
            'metadata' => $asset->metadata,
            'archived' => $asset->isArchived(),
            'archived_at' => $this->format($asset->archived_at, $timezone),
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<Asset>  $assets
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $assets, string $timezone): array
    {
        return collect($assets)
            ->map(fn (Asset $asset): array => $this->make($asset, $timezone))
            ->values()
            ->all();
    }

    private function format(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->timezone($timezone)->toIso8601String();
    }
}
