<?php

declare(strict_types=1);

namespace Mordomus\Notification\Listeners;

use Illuminate\Support\Facades\Log;
use Mordomus\Notification\Contracts\Services\NotificationConsumerServiceInterface;
use Mordomus\Scheduling\Events\QueuedEnvelope;

/**
 * Ponte entre o envelope que chegou pela fila e o consumidor do módulo.
 *
 * É um listener e não uma classe de evento porque o filtro é do Notification
 * (que envelope interessa e o que fazer com um que não é dele); o envelope é
 * do Scheduling e chega pronto.
 *
 * O consumo é idempotente por `dedupe_key`, então repetir a mesma mensagem é
 * seguro — e a exceção sobe de propósito, para que a fila repita em vez de
 * engolir um aviso perdido.
 */
final class QueuedEnvelopeListener
{
    public function __construct(
        private readonly NotificationConsumerServiceInterface $consumer,
    ) {}

    public function handle(QueuedEnvelope $event): void
    {
        if ($event->name() === '' || $event->tenantId() === '') {
            Log::warning('notification.queued_envelope_malformed', [
                'event' => $event->name() !== '' ? $event->name() : null,
                'envelope_keys' => array_keys($event->envelope),
            ]);

            return;
        }

        $this->consumer->consume($event->envelope);
    }
}
