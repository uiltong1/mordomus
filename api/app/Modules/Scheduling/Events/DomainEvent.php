<?php

namespace Mordomus\Scheduling\Events;

/**
 * Evento publicado dentro do próprio processo (ADR-011).
 *
 * No monólito modular não há rede entre módulos, então o consumidor que
 * precisa reagir na mesma transação — o Financial guardando o
 * `schedule_id` do vencimento que acabou de ser materializado — escuta este
 * evento. A fila continua sendo a entrega para quem só precisa observar
 * depois (o Notification, que envia push e e-mail): publicar aqui e na fila ao
 * mesmo tempo é o que evita um segundo motor de eventos só para o monólito.
 */
final readonly class DomainEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(public EventEnvelope $envelope) {}

    public function name(): string
    {
        return $this->envelope->name;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->envelope->payload;
    }

    public function is(string $name): bool
    {
        return $this->envelope->name === $name;
    }
}
