<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Notification\Models\NotificationLog;

interface NotificationLogRepositoryInterface
{
    public function paginateForUser(string $tenantId, string $userId, ?string $channel, OffsetPagination $pagination): LengthAwarePaginator;

    public function create(array $attributes): NotificationLog;

    public function find(string $notificationLogId, ?string $tenantId): ?NotificationLog;

    public function pending(?string $tenantId = null): Collection;

    public function change(NotificationLog $log, array $attributes): NotificationLog;
}
