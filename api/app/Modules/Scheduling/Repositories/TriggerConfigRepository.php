<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

        return $this->find($triggerConfigId, $tenantId) ?? throw TriggerConfigNotFound::make(['trigger_config_id' => $triggerConfigId]);
    }

    public function find(string $triggerConfigId, ?string $tenantId): ?TriggerConfig
    {
        return TriggerConfig::query()
            ->where('id', $triggerConfigId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();
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

    public function forSubject(string $tenantId, string $subjectType, string $subjectId): Collection
    {
        return TriggerConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $subjectType)
            ->where($this->targetColumn($subjectType), $subjectId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function activeFor(string $tenantId): Collection
    {
        return TriggerConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function create(array $attributes): TriggerConfig
    {
        return TriggerConfig::create($attributes);
    }

    public function createFor(string $tenantId, string $subjectType, string $subjectId, array $attributes): TriggerConfig
    {
        return TriggerConfig::create([
            'tenant_id' => $tenantId,
            'subject_type' => $subjectType,
            $this->targetColumn($subjectType) => $subjectId,
        ] + $attributes);
    }

    public function deactivateFor(string $tenantId, string $subjectType, string $subjectId): int
    {
        return TriggerConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $subjectType)
            ->where($this->targetColumn($subjectType), $subjectId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    public function activate(TriggerConfig $config): TriggerConfig
    {
        $config->is_active = true;
        $config->save();

        return $config;
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
