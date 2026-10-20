<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

interface NotificationMessageServiceInterface
{
    public function render(string $event, array $payload, string $tenantId, ?string $recipientId): ?array;
}
