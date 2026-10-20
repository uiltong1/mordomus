<?php

declare(strict_types=1);

namespace Mordomus\Notification\Listeners;

use Illuminate\Support\Facades\Log;
use Mordomus\Notification\Contracts\Services\NotificationConsumerServiceInterface;
use Mordomus\Scheduling\Events\QueuedEnvelope;

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
