<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Events\EventEnvelope;
use Mordomus\Scheduling\Jobs\PublishEvent;

/**
 * Envelope na fila de eventos do Notification.
 *
 * A entrega é a própria fila (SDD §5): nada de HTTP entre módulos. O envelope
 * vai para a frente do commit da transação que o originou, para que o
 * consumidor nunca leia um evento de uma escrita que ainda pode desfazer.
 */
final class EventPublisherService implements EventPublisherServiceInterface
{
    public function publish(string $name, string $tenantId, array $payload): EventEnvelope
    {
        $envelope = EventEnvelope::make($name, $tenantId, $payload);

        PublishEvent::dispatch($envelope->toArray())
            ->onQueue((string) config('scheduling.queues.events'))
            ->afterCommit();

        return $envelope;
    }
}
