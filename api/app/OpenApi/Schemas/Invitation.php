<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Invitation',
    description: 'Convite emitido para um e-mail',
    required: ['id', 'email', 'role', 'expires_at', 'accepted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'convidada@mordomus.test'),
        new OA\Property(property: 'role', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'key', type: 'string', example: 'member'),
            new OA\Property(property: 'name', type: 'string', example: 'Morador'),
        ]),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'accepted_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class Invitation {}
