<?php

namespace Mordomus\Financial\Listeners;

use Mordomus\Financial\Contracts\Services\BillScheduleConsumerServiceInterface;
use Mordomus\Scheduling\Events\DomainEvent;
use Mordomus\Scheduling\Events\EventName;
use Mordomus\Scheduling\Models\TriggerConfig;

final class BillScheduleListener
{
    public function __construct(private readonly BillScheduleConsumerServiceInterface $consumer) {}

    public function handle(DomainEvent $event): void
    {
        $payload = $event->payload();

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
