<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Error',
    description: 'Envelope de erro da API',
    required: ['error'],
    properties: [
        new OA\Property(property: 'error', type: 'object', required: ['code', 'message', 'details', 'request_id'], properties: [
            new OA\Property(property: 'code', type: 'string', example: 'validation_failed'),
            new OA\Property(property: 'message', type: 'string', example: 'Dados inválidos.'),
            new OA\Property(property: 'details', description: 'Detalhes livres: objeto por campo (validação) ou lista de esperados/recebidos', oneOf: [
                new OA\Schema(type: 'object', additionalProperties: true, example: ['email' => ['O e-mail já está em uso.']]),
                new OA\Schema(type: 'array', items: new OA\Items(type: 'string'), example: ['01ABC']),
            ]),
            new OA\Property(property: 'request_id', type: 'string', nullable: true, example: 'smoke-0001'),
        ]),
    ],
)]
final class Error {}
