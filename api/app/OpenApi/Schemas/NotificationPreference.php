<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationPreference',
    description: 'Quiet hours, digest e horário preferido em três camadas: a do morador, a da casa e a que vale depois da precedência',
    required: ['own', 'house', 'effective'],
    properties: [
        new OA\Property(property: 'own', type: 'object', nullable: true, description: 'O que este morador configurou; null quando não configurou nada', ref: '#/components/schemas/NotificationPreferenceLayer'),
        new OA\Property(property: 'house', type: 'object', nullable: true, description: 'O padrão da residência (`user_id` nulo)', ref: '#/components/schemas/NotificationPreferenceLayer'),
        new OA\Property(property: 'effective', type: 'object', description: 'A precedência resolvida: morador → casa → padrão do ambiente', ref: '#/components/schemas/NotificationPreferenceLayer'),
    ],
)]
final class NotificationPreference {}

#[OA\Schema(
    schema: 'NotificationPreferenceLayer',
    description: 'Uma camada da preferência de notificação. A janela de silêncio pode atravessar a meia-noite: 22:00–07:00 é uma janela só',
    required: ['quiet_window_wraps_midnight'],
    properties: [
        new OA\Property(property: 'quiet_start', type: 'string', nullable: true, pattern: '^\d{2}:\d{2}$', example: '22:00'),
        new OA\Property(property: 'quiet_end', type: 'string', nullable: true, pattern: '^\d{2}:\d{2}$', example: '07:00'),
        new OA\Property(property: 'quiet_window_wraps_midnight', type: 'boolean', example: true),
        new OA\Property(property: 'digest', type: 'string', nullable: true, enum: ['instant', 'daily'], description: '`instant` entrega por push assim que pode; `daily` entrega por e-mail no horário preferido'),
        new OA\Property(property: 'preferred_hour', type: 'string', nullable: true, pattern: '^\d{2}:\d{2}$', example: '09:00'),
    ],
)]
final class NotificationPreferenceLayer {}
