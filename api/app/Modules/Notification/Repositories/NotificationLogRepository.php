<?php

declare(strict_types=1);

namespace Mordomus\Notification\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Notification\Contracts\Repositories\NotificationLogRepositoryInterface;
use Mordomus\Notification\Models\NotificationLog;

final class NotificationLogRepository implements NotificationLogRepositoryInterface
{
    public function paginateForUser(string $tenantId, string $userId, ?string $channel, OffsetPagination $pagination): LengthAwarePaginator
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->when($channel !== null, fn (Builder $query) => $query->where('channel', $channel))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);
    }

    public function create(array $attributes): NotificationLog
    {
        // `refresh()` porque os padrões do banco (`status = queued`) não voltam
        // no model que o `create` devolve, e a entrega usa essa linha.
        return NotificationLog::create($attributes)->refresh();
    }

    public function find(string $notificationLogId, ?string $tenantId): ?NotificationLog
    {
        return $this->query()
            ->where('id', $notificationLogId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    public function pending(?string $tenantId = null): Collection
    {
        return $this->query()
            ->where('status', NotificationLog::STATUS_QUEUED)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->orderBy('available_at')
            ->orderBy('id')
            ->get();
    }

    public function change(NotificationLog $log, array $attributes): NotificationLog
    {
        $log->fill($attributes);
        $log->save();

        return $log;
    }

    /** @return Builder<NotificationLog> */
    private function query(): Builder
    {
        return NotificationLog::query();
    }
}
