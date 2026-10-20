<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Events\DomainEvent;
use Mordomus\Scheduling\Events\EventEnvelope;
use Mordomus\Scheduling\Jobs\PublishEvent;

final class EventPublisherService implements EventPublisherServiceInterface
{
    public function publish(string $name, string $tenantId, array $payload): EventEnvelope
    {
        $envelope = EventEnvelope::make($name, $tenantId, $payload);

        event(new DomainEvent($envelope));

        PublishEvent::dispatch($envelope->toArray())
            ->onQueue((string) config('scheduling.queues.events'))
            ->afterCommit();

        return $envelope;
    }
}
