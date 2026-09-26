<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Preferences',
    description: 'Preferências da residência',
    required: ['tenant_id', 'preferred_hour', 'quiet_hours', 'channels'],
    properties: [
        new OA\Property(property: 'tenant_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00'),
        new OA\Property(property: 'quiet_hours', type: 'object', properties: [
            new OA\Property(property: 'start', type: 'string', example: '22:00'),
            new OA\Property(property: 'end', type: 'string', example: '07:00'),
        ]),
        new OA\Property(property: 'channels', type: 'object', properties: [
            new OA\Property(property: 'email', type: 'boolean', example: true),
            new OA\Property(property: 'push', type: 'boolean', example: false),
            new OA\Property(property: 'in_app', type: 'boolean', example: true),
        ]),
    ],
)]
final class Preferences {}
