<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Mordomus\Notification\Models\NotificationLog;

interface NotificationDeliveryServiceInterface
{
    public function deliver(NotificationLog $log): void;

    public function fail(NotificationLog $log, string $reason): void;
}
