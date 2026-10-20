<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Events;

final readonly class QueuedEnvelope
{
    public function __construct(public array $envelope) {}

    public function name(): string
    {
        return (string) ($this->envelope['event'] ?? '');
    }

    public function tenantId(): string
    {
        return (string) ($this->envelope['tenant_id'] ?? '');
    }

    public function payload(): array
    {
        $payload = $this->envelope['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }
}
