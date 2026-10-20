<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Illuminate\Http\Request;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Notification\Contracts\Repositories\NotificationLogRepositoryInterface;
use Mordomus\Notification\Contracts\Services\NotificationLogServiceInterface;
use Mordomus\Notification\Http\Resources\NotificationLogResource;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;

final class NotificationLogService implements NotificationLogServiceInterface
{
    public function __construct(
        private readonly NotificationLogRepositoryInterface $logs,
        private readonly NotificationLogResource $resource,
        private readonly TenantCalendarServiceInterface $calendar,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $userId = (string) $request->user()->id;
        $pagination = OffsetPagination::from($request);

        $channel = $request->input('channel');

        $logs = $this->logs->paginateForUser(
            $tenantId,
            $userId,
            is_string($channel) && $channel !== '' ? $channel : null,
            $pagination,
        );

        return [
            'data' => $this->resource->collection($logs->getCollection(), $this->calendar->timezone($tenantId)),
            'meta' => $pagination->meta($logs->total(), $logs->lastPage()),
        ];
    }

    private function tenantId(Request $request): string
    {

        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
