<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Mordomus\Http\Exceptions\ResourceNotFound;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Scheduling\Contracts\Repositories\TriggerConfigRepositoryInterface;
use Mordomus\Scheduling\Exceptions\TriggerConfigNotFound;
use Mordomus\Scheduling\Models\TriggerConfig;

final class TriggerConfigRepository implements TriggerConfigRepositoryInterface
{
    public function paginate(
        ?string $subjectType,
        ?string $subjectId,
        OffsetPagination $pagination,
    ): LengthAwarePaginator {
        $query = TriggerConfig::query();

        if ($subjectType !== null && $subjectId !== null) {
            $query->where($this->targetColumn($subjectType), $subjectId);
        }

        return $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);
    }

    public function findOrFail(string $triggerConfigId, ?string $tenantId): TriggerConfig
    {
        if ($tenantId === null) {
            throw ResourceNotFound::make();
        }

        $config = TriggerConfig::query()
            ->where('id', $triggerConfigId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($config === null) {
            throw TriggerConfigNotFound::make(['trigger_config_id' => $triggerConfigId]);
        }

        return $config;
    }

    public function findByTitle(string $tenantId, string $subjectType, string $subjectId, string $title): ?TriggerConfig
    {
        return TriggerConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $subjectType)
            ->where($this->targetColumn($subjectType), $subjectId)
            ->where('title', $title)
            ->first();
    }

    public function create(array $attributes): TriggerConfig
    {
        return TriggerConfig::create($attributes);
    }

    public function change(TriggerConfig $config, array $attributes): TriggerConfig
    {
        $config->fill($attributes);
        $config->save();

        return $config;
    }

    public function delete(TriggerConfig $config): void
    {
        $config->delete();
    }

    private function targetColumn(string $subjectType): string
    {
        return $subjectType === TriggerConfig::SUBJECT_BILL ? 'bill_id' : 'asset_id';
    }
}
