<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Mordomus\Http\Exceptions\ResourceNotFound;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Maintenance\Contracts\Repositories\AssetRepositoryInterface;
use Mordomus\Maintenance\Models\Asset;

final class AssetRepository implements AssetRepositoryInterface
{
    public function paginate(
        ?string $roomId,
        ?string $category,
        bool $includeArchived,
        OffsetPagination $pagination,
    ): LengthAwarePaginator {
        $query = Asset::query();

        if ($roomId !== null && $roomId !== '') {
            $query->where('room_id', $roomId);
        }

        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        return $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);
    }

    public function findOrFail(string $assetId, ?string $tenantId): Asset
    {
        if ($tenantId === null) {
            throw ResourceNotFound::make();
        }

        $asset = Asset::query()
            ->where('id', $assetId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($asset === null) {
            throw ResourceNotFound::make();
        }

        return $asset;
    }

    public function create(array $attributes): Asset
    {
        return Asset::create($attributes);
    }

    public function change(Asset $asset, array $attributes): Asset
    {
        $asset->fill($attributes);
        $asset->save();

        return $asset;
    }

    public function archive(Asset $asset): Asset
    {
        $asset->archive();
        $asset->refresh();

        return $asset;
    }

    public function restore(Asset $asset): Asset
    {
        $asset->restore();
        $asset->refresh();

        return $asset;
    }
}
