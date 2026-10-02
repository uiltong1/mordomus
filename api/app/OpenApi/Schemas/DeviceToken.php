<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeviceToken',
    description: 'Assinatura de Web Push de um morador. `p256dh` e `auth` são segredo do lado do navegador e não saem na resposta',
    required: ['id', 'platform', 'endpoint', 'last_seen_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D5'),
        new OA\Property(property: 'platform', type: 'string', enum: ['web', 'android', 'ios']),
        new OA\Property(property: 'endpoint', type: 'string', format: 'uri', example: 'https://fcm.googleapis.com/fcm/send/abc123'),
        new OA\Property(property: 'last_seen_at', type: 'string', nullable: true, format: 'date-time', description: 'Última vez que o navegador registrou esta assinatura'),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class DeviceToken {}
