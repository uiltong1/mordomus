<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Health',
    description: 'Saúde do monólito',
    required: ['service', 'status', 'checks', 'request_id', 'duration_ms', 'timestamp'],
    properties: [
        new OA\Property(property: 'service', type: 'string', example: 'Mordomus'),
        new OA\Property(property: 'status', type: 'string', enum: ['ok', 'degraded'], example: 'ok'),
        new OA\Property(property: 'checks', type: 'object', properties: [
            new OA\Property(property: 'database', type: 'object', properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['up', 'down'], example: 'up'),
                new OA\Property(property: 'connection', type: 'string', example: 'pgsql'),
            ]),
            new OA\Property(property: 'cache', type: 'string', example: 'redis'),
            new OA\Property(property: 'queue', type: 'string', example: 'redis'),
        ]),
        new OA\Property(property: 'tenant', type: 'string', nullable: true, description: 'Residência resolvida pelo backend'),
        new OA\Property(property: 'request_id', type: 'string', example: 'smoke-0001'),
        new OA\Property(property: 'duration_ms', type: 'number', format: 'float', example: 3.2),
        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
    ],
)]
final class Health {}
