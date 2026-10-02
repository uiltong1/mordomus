<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

/**
 * Renderização do aviso de um evento: o que o morador lê.
 *
 * O texto nasce aqui uma vez só e é gravado na linha da notificação, porque
 * o push e o e-mail precisam do mesmo número e da mesma frase — duas versões
 * do mesmo aviso é o jeito de a casa receber "R$ 62,48" no push e "R$ 62,5" no
 * e-mail.
 */
interface NotificationMessageServiceInterface
{
    /**
     * Monta o aviso de um evento para um destinatário.
     *
     * @param  array<string, mixed>  $payload  o payload do evento
     * @return array{template: string, subject: string, body: array<string, mixed>}|null
     *                                                                                   `null` quando o evento não é do módulo — quem consome decide o
     *                                                                                   que fazer com um evento que não é dele
     */
    public function render(string $event, array $payload, string $tenantId, ?string $recipientId): ?array;
}
