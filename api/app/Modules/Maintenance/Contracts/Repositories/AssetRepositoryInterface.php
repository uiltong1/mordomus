<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Maintenance\Models\Asset;

interface AssetRepositoryInterface
{
    public function paginate(
        ?string $roomId,
        ?string $category,
        bool $includeArchived,
        OffsetPagination $pagination,
    ): LengthAwarePaginator;

    public function findOrFail(string $assetId, ?string $tenantId): Asset;

    public function create(array $attributes): Asset;

    public function change(Asset $asset, array $attributes): Asset;

    public function archive(Asset $asset): Asset;

    public function restore(Asset $asset): Asset;
}
