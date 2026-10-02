<?php

declare(strict_types=1);

namespace Mordomus\Notification\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mordomus\Notification\Contracts\Repositories\DeviceTokenRepositoryInterface;
use Mordomus\Notification\Models\DeviceToken;

final class DeviceTokenRepository implements DeviceTokenRepositoryInterface
{
    public function forUser(string $tenantId, string $userId): Collection
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function find(string $deviceTokenId, ?string $tenantId): ?DeviceToken
    {
        return $this->query()
            ->where('id', $deviceTokenId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    public function findByEndpoint(string $tenantId, string $userId, string $endpoint): ?DeviceToken
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('endpoint', $endpoint)
            ->first();
    }

    public function create(array $attributes): DeviceToken
    {
        return DeviceToken::create($attributes)->refresh();
    }

    public function change(DeviceToken $deviceToken, array $attributes): DeviceToken
    {
        $deviceToken->fill($attributes);
        $deviceToken->save();

        return $deviceToken;
    }

    public function forget(string $tenantId, string $userId, string $endpoint): int
    {
        return DeviceToken::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('endpoint', $endpoint)
            ->delete();
    }

    /** @return Builder<DeviceToken> */
    private function query(): Builder
    {
        return DeviceToken::query();
    }
}
