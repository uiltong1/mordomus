<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

interface BillScheduleConsumerServiceInterface
{
    public function onOccurrenceCreated(string $tenantId, array $payload): void;

    public function projectDueDates(string $tenantId): int;

    public function onDue(string $tenantId, array $payload): void;

    public function markOverdue(string $tenantId, string $today): int;
}
