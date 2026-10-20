<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Repositories;

use Illuminate\Support\Collection;
use Mordomus\Notification\Models\DeviceToken;

interface DeviceTokenRepositoryInterface
{
    public function forUser(string $tenantId, string $userId): Collection;

    public function find(string $deviceTokenId, ?string $tenantId): ?DeviceToken;

    public function findByEndpoint(string $tenantId, string $userId, string $endpoint): ?DeviceToken;

    public function create(array $attributes): DeviceToken;

    public function change(DeviceToken $deviceToken, array $attributes): DeviceToken;

    public function forget(string $tenantId, string $userId, string $endpoint): int;
}
