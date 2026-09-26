<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Member',
    description: 'Membro ativo de uma residência',
    required: ['id', 'user', 'role', 'status', 'capabilities'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'user', ref: User::class),
        new OA\Property(property: 'role', type: 'object', required: ['id', 'key', 'name'], properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'key', type: 'string', example: 'member'),
            new OA\Property(property: 'name', type: 'string', example: 'Morador'),
        ]),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['bills.pay']),
    ],
)]
final class Member {}
