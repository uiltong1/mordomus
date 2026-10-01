<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Events\DomainEvent;
use Mordomus\Scheduling\Events\EventEnvelope;
use Mordomus\Scheduling\Jobs\PublishEvent;

/**
 * Publicação em dois destinos: o processo e a fila de eventos do Notification.
 *
 * A entrega assíncrona é a fila (SDD §5): nada de HTTP entre módulos, e o
 * envelope vai para a frente do commit da transação que o originou, para que
 * o consumidor nunca leia um evento de uma escrita que ainda pode desfazer.
 *
 * O `DomainEvent` sai antes, dentro da transação, para o consumidor in-process
 * (o Financial, ao gravar o `schedule_id` do vencimento): a linha que ele
 * referencia é a mesma que a transação está gravando, e esperar o commit
 * transformaria o prazo de materialização numa corrida.
 */
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
