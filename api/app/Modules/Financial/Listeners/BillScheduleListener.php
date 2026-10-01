<?php

namespace Mordomus\Financial\Listeners;

use Mordomus\Financial\Contracts\Services\BillScheduleConsumerServiceInterface;
use Mordomus\Scheduling\Events\DomainEvent;
use Mordomus\Scheduling\Events\EventName;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Ponte entre o evento publicado pelo Scheduling e o módulo que o consome.
 *
 * É um listener e não uma classe de evento porque o filtro é do Financial
 * (que evento interessa, e o que fazer quando o alvo não é uma conta); o
 * envelope é do Scheduling e chega pronto.
 */
final class BillScheduleListener
{
    public function __construct(private readonly BillScheduleConsumerServiceInterface $consumer) {}

    public function handle(DomainEvent $event): void
    {
        $payload = $event->payload();

        // Vencimento e aviso são de conta; occurrence.completed é do ciclo de
        // manutenção e não diz nada sobre dinheiro.
        if (! in_array($event->name(), [EventName::OCCURRENCE_CREATED, EventName::SCHEDULE_DUE], true)) {
            return;
        }

        if (($payload['subject_type'] ?? null) !== TriggerConfig::SUBJECT_BILL) {
            return;
        }

        $tenantId = $event->envelope->tenantId;

        match ($event->name()) {
            EventName::OCCURRENCE_CREATED => $this->consumer->onOccurrenceCreated($tenantId, $payload),
            default => $this->consumer->onDue($tenantId, $payload),
        };
    }
}
