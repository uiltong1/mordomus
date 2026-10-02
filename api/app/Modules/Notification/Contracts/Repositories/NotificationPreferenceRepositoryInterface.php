<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Repositories;

use Mordomus\Notification\Models\NotificationPreference;

interface NotificationPreferenceRepositoryInterface
{
    public function findForUser(string $tenantId, string $userId): ?NotificationPreference;

    public function findForHouse(string $tenantId): ?NotificationPreference;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertForUser(string $tenantId, string $userId, array $attributes): NotificationPreference;
}
