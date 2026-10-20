<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Carbon\CarbonImmutable;
use Mordomus\Notification\Models\NotificationPreference;

interface QuietWindowServiceInterface
{
    public function effective(string $tenantId, string $userId): NotificationPreference;

    public function deliverAt(NotificationPreference $preference, CarbonImmutable $now, string $timezone): CarbonImmutable;

    public function isQuietAt(NotificationPreference $preference, CarbonImmutable $now, string $timezone): bool;
}
