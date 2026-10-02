<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationLog',
    description: 'Uma notificação: o que foi avisado, por qual canal e com que resultado. É também a prova de que o aviso saiu e o que ele era',
    required: ['id', 'channel', 'template', 'subject', 'body', 'status', 'available_at', 'sent_at', 'error', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D6'),
        new OA\Property(property: 'channel', type: 'string', enum: ['push', 'email', 'inapp']),
        new OA\Property(property: 'template', type: 'string', example: 'mordomus::bill.due'),
        new OA\Property(property: 'subject', type: 'string', example: 'Conta de luz vence em 10/04/2026'),
        new OA\Property(property: 'body', type: 'object', description: 'Conteúdo do aviso (`title`, `lines`, `tag`, `url`, `data`); é o que o canal traduz para o formato do provedor', additionalProperties: true),
        new OA\Property(property: 'status', type: 'string', enum: ['queued', 'sent', 'failed'], description: '`queued` é a intenção com o instante em que pode sair; `failed` é a DLQ do módulo'),
        new OA\Property(property: 'available_at', type: 'string', nullable: true, format: 'date-time', description: 'Instante em que a janela de silêncio permitiu sair'),
        new OA\Property(property: 'sent_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'error', type: 'string', nullable: true, description: 'Motivo da falha, quando houve'),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class NotificationLog {}
