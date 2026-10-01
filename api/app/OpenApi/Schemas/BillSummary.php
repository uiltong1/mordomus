<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BillSummary',
    description: 'Consolidação mensal da residência: quanto venceu, quanto foi pago e o que ficou em aberto',
    required: ['month', 'timezone', 'totals', 'counts', 'by_status', 'by_category'],
    properties: [
        new OA\Property(property: 'month', type: 'string', example: '2026-04'),
        new OA\Property(property: 'timezone', type: 'string', example: 'America/Sao_Paulo'),
        new OA\Property(property: 'totals', type: 'object', description: 'Textos decimais com duas casas; a soma fecha com o total (R5)', properties: [
            new OA\Property(property: 'due', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '2450.00', description: 'Tudo que venceu no mês, qualquer estado'),
            new OA\Property(property: 'paid', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '1200.00'),
            new OA\Property(property: 'open', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '850.00'),
            new OA\Property(property: 'overdue', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '400.00'),
            new OA\Property(property: 'cancelled', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '0.00'),
        ]),
        new OA\Property(property: 'counts', type: 'object', properties: [
            new OA\Property(property: 'occurrences', type: 'integer', example: 6),
            new OA\Property(property: 'paid', type: 'integer', example: 3),
            new OA\Property(property: 'open', type: 'integer', example: 2),
            new OA\Property(property: 'overdue', type: 'integer', example: 1),
            new OA\Property(property: 'cancelled', type: 'integer', example: 0),
        ]),
        new OA\Property(property: 'by_status', type: 'object', description: 'Contagem por estado, sempre com as quatro chaves', additionalProperties: true),
        new OA\Property(property: 'by_category', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'category', type: 'string', nullable: true, example: 'energia'),
            new OA\Property(property: 'occurrences', type: 'integer'),
            new OA\Property(property: 'due', type: 'string', pattern: '^-?\d+\.\d{2}$'),
            new OA\Property(property: 'paid', type: 'string', pattern: '^-?\d+\.\d{2}$'),
            new OA\Property(property: 'open', type: 'string', pattern: '^-?\d+\.\d{2}$'),
        ])),
    ],
)]
final class BillSummary {}
