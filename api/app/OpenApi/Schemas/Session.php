<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Session',
    description: 'Sessão emitida pelas rotas de autenticação',
    required: ['token_type', 'access_token', 'expires_in', 'active_tenant', 'refresh_token', 'refresh_expires_in', 'user', 'tenants'],
    properties: [
        new OA\Property(property: 'token_type', type: 'string', example: 'bearer'),
        new OA\Property(property: 'access_token', type: 'string', description: 'JWT RS256 com as claims sub, tid e tenants'),
        new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
        new OA\Property(property: 'active_tenant', type: 'string', nullable: true, example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'refresh_token', type: 'string', description: 'Token opaco de uso único'),
        new OA\Property(property: 'refresh_expires_in', type: 'integer', example: 2592000),
        new OA\Property(property: 'user', ref: User::class),
        new OA\Property(property: 'tenants', type: 'array', items: new OA\Items(ref: TenantSummary::class)),
    ],
)]
final class Session {}
