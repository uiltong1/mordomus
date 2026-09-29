<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Envelope padrão de todo evento publicado (TECHSPEC §7).
 *
 * `event_id` e `occurred_at` são do envelope, não do payload: o consumidor
 * identifica a mensagem por `event_id` e deduplica por `dedupe_key`, que é
 * determinístico dentro do payload — uma reentrega da fila tem de produzir a
 * mesma chave, senão o at-least-once vira spam.
 */
final readonly class EventEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $name,
        public string $eventId,
        public string $occurredAt,
        public string $tenantId,
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
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

    /**
     * Minúsculo como o `HasUlids` do Eloquent grava: o `event_id` atravessa a
     * fila e volta para o consumidor, e duas formas de escrever o mesmo id
     * fariam a deduplicação falhar.
     */
    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** @return array<string, mixed> */
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
