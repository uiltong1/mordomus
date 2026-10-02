<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

/**
 * Consumo do envelope publicado pelos módulos.
 *
 * A fila entrega o mesmo evento mais de uma vez (at-least-once), então o que
 * sai daqui é escrito para a segunda entrega não virar um segundo aviso: a
 * `dedupe_key` da linha é o que segura, e ela é gravada no banco antes de
 * qualquer canal ser chamado.
 */
interface NotificationConsumerServiceInterface
{
    /**
     * @param  array<string, mixed>  $envelope  o envelope padrão do módulo publicador
     * @return int quantas notificações foram enfileiradas nesta entrega
     */
    public function consume(array $envelope): int;
}
