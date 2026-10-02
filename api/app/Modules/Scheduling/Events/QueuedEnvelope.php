<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Events;

/**
 * Envelope que chegou pela fila.
 *
 * É distinto do `DomainEvent` de propósito. O `DomainEvent` é o aviso de que o
 * módulo **publicou** e sai dentro da transação, para o consumidor que precisa
 * da linha ainda não commitada. Este é o aviso de que a mensagem **chegou**, e
 * só existe aqui — dentro da tentativa da fila, onde o retry e a DLQ do módulo
 * que trata a mensagem valem.
 *
 * Os dois carregam o mesmo envelope; o que muda é quem pode ouvir cada um.
 */
final readonly class QueuedEnvelope
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(public array $envelope) {}

    public function name(): string
    {
        return (string) ($this->envelope['event'] ?? '');
    }

    public function tenantId(): string
    {
        return (string) ($this->envelope['tenant_id'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = $this->envelope['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }
}
