<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SplitRule',
    description: 'Regra de divisão de uma conta: como a cota de cada morador é calculada. `bill_id` nulo é a regra padrão da casa, que vale para toda conta sem regra própria',
    required: ['id', 'tenant_id', 'mode', 'is_active', 'is_house_default', 'entries'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'bill_id', type: 'string', nullable: true, description: 'Conta da regra; nulo na regra padrão da casa'),
        new OA\Property(property: 'bill_name', type: 'string', nullable: true, example: 'Aluguel'),
        new OA\Property(property: 'mode', type: 'string', enum: ['EQUAL', 'WEIGHTED', 'PERCENT', 'CUSTOM'], description: 'Regime do cálculo: igual, proporcional ao peso, proporcional ao percentual ou cota fechada'),
        new OA\Property(property: 'is_active', type: 'boolean', description: 'Regra pausada não divide a conta, que volta ao padrão da casa'),
        new OA\Property(property: 'is_house_default', type: 'boolean'),
        new OA\Property(property: 'entries', type: 'array', description: 'Participantes, na ordem em que a casa cadastrou; o resíduo de arredondamento da R5 fica com o último', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'user_id', type: 'string'),
            new OA\Property(property: 'user_name', type: 'string', nullable: true, example: 'Ana'),
            new OA\Property(property: 'weight', type: 'string', nullable: true, pattern: '^-?\d+\.\d{2}$', example: '1.50', description: 'Preenchido só em `WEIGHTED`'),
            new OA\Property(property: 'percent', type: 'string', nullable: true, pattern: '^-?\d+\.\d{2}$', example: '40.00', description: 'Preenchido só em `PERCENT`'),
            new OA\Property(property: 'fixed_amount', type: 'string', nullable: true, pattern: '^-?\d+\.\d{2}$', example: '750.00', description: 'Preenchido só em `CUSTOM`'),
        ])),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class SplitRule {}
