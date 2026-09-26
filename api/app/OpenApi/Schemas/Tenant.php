<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Tenant',
    description: 'Residência com as capabilities do membro autenticado',
    required: ['id', 'name', 'slug', 'timezone', 'preferred_hour', 'archived', 'capabilities', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'name', type: 'string', example: 'Casa Principal'),
        new OA\Property(property: 'slug', type: 'string', example: 'casa-principal'),
        new OA\Property(property: 'timezone', type: 'string', example: 'America/Sao_Paulo'),
        new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00'),
        new OA\Property(property: 'archived', type: 'boolean', example: false),
        new OA\Property(property: 'archived_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'role', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'key', type: 'string', example: 'owner'),
            new OA\Property(property: 'name', type: 'string', example: 'Proprietário'),
        ]),
        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['tenant.manage']),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class Tenant {}
