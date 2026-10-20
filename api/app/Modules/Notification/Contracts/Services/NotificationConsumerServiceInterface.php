<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

interface NotificationConsumerServiceInterface
{
    public function consume(array $envelope): int;
}
