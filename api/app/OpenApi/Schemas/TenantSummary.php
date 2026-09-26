<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TenantSummary',
    description: 'Residência resumida, como aparece nas listas de sessão do usuário',
    required: ['id', 'name', 'slug', 'role', 'role_key', 'capabilities'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'name', type: 'string', example: 'Casa Principal'),
        new OA\Property(property: 'slug', type: 'string', example: 'casa-principal'),
        new OA\Property(property: 'role', type: 'string', example: 'Proprietário'),
        new OA\Property(property: 'role_key', type: 'string', example: 'owner'),
        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['rooms.manage']),
    ],
)]
final class TenantSummary {}
