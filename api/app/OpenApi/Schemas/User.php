<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    description: 'Perfil do usuário autenticado',
    required: ['id', 'name', 'email', 'locale'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'name', type: 'string', example: 'Ana Souza'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@mordomus.test'),
        new OA\Property(property: 'locale', type: 'string', example: 'pt_BR'),
    ],
)]
final class User {}
