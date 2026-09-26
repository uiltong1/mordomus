<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Asset',
    description: 'Item do inventário, sempre dentro de um cômodo da residência',
    required: ['id', 'tenant_id', 'room_id', 'name', 'archived'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'tenant_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'),
        new OA\Property(property: 'room_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D2'),
        new OA\Property(property: 'name', type: 'string', example: 'Ar-condicionado'),
        new OA\Property(property: 'category', type: 'string', nullable: true, example: 'eletro'),
        new OA\Property(property: 'brand', type: 'string', nullable: true, example: 'Springer'),
        new OA\Property(property: 'model', type: 'string', nullable: true, example: 'X-12000'),
        new OA\Property(property: 'acquired_at', type: 'string', nullable: true, format: 'date-time', example: '2024-01-15T00:00:00-03:00'),
        new OA\Property(property: 'warranty_until', type: 'string', nullable: true, format: 'date-time', example: '2027-03-15T00:00:00-03:00'),
        new OA\Property(property: 'metadata', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'archived', type: 'boolean', example: false),
        new OA\Property(property: 'archived_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class Asset {}
