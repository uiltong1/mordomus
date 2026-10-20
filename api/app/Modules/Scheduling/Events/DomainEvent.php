<?php

namespace Mordomus\Scheduling\Events;

final readonly class DomainEvent
{
    public function __construct(public EventEnvelope $envelope) {}

    public function name(): string
    {
        return $this->envelope->name;
    }

    public function payload(): array
    {
        return $this->envelope->payload;
    }

    public function is(string $name): bool
    {
        return $this->envelope->name === $name;
    }
}
