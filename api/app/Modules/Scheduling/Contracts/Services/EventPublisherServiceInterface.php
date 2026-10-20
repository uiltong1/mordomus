<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Mordomus\Scheduling\Events\EventEnvelope;

interface EventPublisherServiceInterface
{
    public function publish(string $name, string $tenantId, array $payload): EventEnvelope;
}
