<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Mordomus\Notification\Models\DeviceToken;

/**
 * Canal de push (ADR-001).
 *
 * Existe como interface porque a fronteira é real: o Web Push entrega no
 * navegador pelo endpoint da assinatura, e o FCM entrega em app nativo por
 * token — são protocolos diferentes, e o `NotificationDeliveryService` precisa
 * não saber qual dos dois está falando.
 */
interface PushChannelInterface
{
    /**
     * Envia o aviso para a assinatura.
     *
     * @param  array<string, mixed>  $content  o conteúdo gravado na linha (`title`, `lines`, `tag`, `url`, `data`)
     * @return bool `true` entregou; `false` a assinatura não existe mais (404/410) e a linha deve sair
     *
     * @throws \Throwable quando o serviço não deu resposta utilizável — é retry, e não descarte
     */
    public function send(DeviceToken $device, array $content): bool;
}
