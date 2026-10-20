<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class EventEnvelope
{
    public function __construct(
        public string $name,
        public string $eventId,
        public string $occurredAt,
        public string $tenantId,
        public array $payload,
    ) {}

    public static function make(string $name, string $tenantId, array $payload, ?string $eventId = null, ?string $occurredAt = null): self
    {
        return new self(
            $name,
            $eventId ?? self::ulid(),
            $occurredAt ?? CarbonImmutable::now('UTC')->toIso8601String(),
            $tenantId,
            $payload,
        );
    }

    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function toArray(): array
    {
        return [
            'event' => $this->name,
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'tenant_id' => $this->tenantId,
            'payload' => $this->payload,
        ];
    }
}
