<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Mordomus\Notification\Models\NotificationLog;

interface NotificationDeliveryServiceInterface
{
    /**
     * Entrega uma notificação pelo canal da linha.
     *
     * A linha só muda de `queued` para `sent` quando o canal respondeu. A
     * exceção é o caminho do retry: ela sobe, o job tenta de novo, e a linha
     * continua `queued` enquanto ninguém respondeu por ela.
     */
    public function deliver(NotificationLog $log): void;

    /**
     * Registra a linha como não entregue.
     *
     * Quem chama é o job quando as tentativas acabaram — é a DLQ do módulo, e
     * o registro é o que fica depois dela.
     */
    public function fail(NotificationLog $log, string $reason): void;
}
