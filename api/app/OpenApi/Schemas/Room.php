<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Room',
    description: 'Cômodo do imóvel',
    required: ['id', 'tenant_id', 'name', 'sort_order', 'archived'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'tenant_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'),
        new OA\Property(property: 'name', type: 'string', example: 'Sala'),
        new OA\Property(property: 'icon', type: 'string', nullable: true, example: 'sofa'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
        new OA\Property(property: 'archived', type: 'boolean', example: false),
        new OA\Property(property: 'archived_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class Room {}
