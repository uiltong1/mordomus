<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Mordomus\Scheduling\Events\EventEnvelope;

/**
 * Publicação dos eventos do motor na fila.
 *
 * Existe como interface porque a fronteira é real: quem publica precisa saber
 * que a entrega é assíncrona, e o Notification (T6.1) precisa poder observar
 * o envelope sem conhecer o motor.
 */
interface EventPublisherServiceInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $name, string $tenantId, array $payload): EventEnvelope;
}
