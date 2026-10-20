<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Illuminate\Http\Request;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Notification\Contracts\Repositories\NotificationPreferenceRepositoryInterface;
use Mordomus\Notification\Contracts\Services\NotificationPreferenceServiceInterface;
use Mordomus\Notification\Contracts\Services\QuietWindowServiceInterface;
use Mordomus\Notification\Http\Resources\NotificationPreferenceResource;

final class NotificationPreferenceService implements NotificationPreferenceServiceInterface
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferences,
        private readonly QuietWindowServiceInterface $quiet,
        private readonly NotificationPreferenceResource $resource,
    ) {}

    public function show(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $userId = (string) $request->user()->id;

        return ['data' => $this->resource->make(
            $this->preferences->findForUser($tenantId, $userId),
            $this->preferences->findForHouse($tenantId),
            $this->quiet->effective($tenantId, $userId),
        )];
    }

    public function update(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $userId = (string) $request->user()->id;

        $preference = $this->preferences->upsertForUser($tenantId, $userId, [
            'quiet_start' => $request->input('quiet_start'),
            'quiet_end' => $request->input('quiet_end'),

            'digest' => $request->input('digest'),
            'preferred_hour' => $request->input('preferred_hour'),
        ]);

        return ['data' => $this->resource->make(
            $preference,
            $this->preferences->findForHouse($tenantId),
            $this->quiet->effective($tenantId, $userId),
        )];
    }

    private function tenantId(Request $request): string
    {

        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
