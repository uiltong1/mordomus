<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OccurrenceSplit',
    description: 'Divisão de um vencimento: a regra que a produziu e as cotas. `scope` diz se `shares` traz a divisão inteira ou só a cota de quem perguntou — em leitura restrita a soma da lista não fecha, e `total` continua sendo o valor do vencimento (R5)',
    required: ['bill_occurrence_id', 'bill_id', 'due_date', 'amount', 'status', 'split_rule_id', 'mode', 'is_house_default', 'total', 'scope', 'shares'],
    properties: [
        new OA\Property(property: 'bill_occurrence_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'),
        new OA\Property(property: 'bill_id', type: 'string'),
        new OA\Property(property: 'bill_name', type: 'string', nullable: true, example: 'Conta de luz'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', example: '2026-04-10'),
        new OA\Property(property: 'amount', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '187.43'),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'paid', 'overdue', 'cancelled']),
        new OA\Property(property: 'split_rule_id', type: 'string'),
        new OA\Property(property: 'mode', type: 'string', enum: ['EQUAL', 'WEIGHTED', 'PERCENT', 'CUSTOM']),
        new OA\Property(property: 'is_house_default', type: 'boolean', description: 'A regra que vale é a padrão da casa, e não uma desta conta'),
        new OA\Property(property: 'total', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '187.43', description: 'Valor do vencimento, sobre o qual as cotas somam 100%'),
        new OA\Property(property: 'scope', type: 'string', enum: ['all', 'own'], description: 'Alcance da lista: `all` para quem administra a divisão, `own` para quem só lê a cota própria'),
        new OA\Property(property: 'shares', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'user_id', type: 'string'),
            new OA\Property(property: 'user_name', type: 'string', nullable: true, example: 'Ana'),
            new OA\Property(property: 'share_amount', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '62.48'),
            new OA\Property(property: 'settled', type: 'boolean'),
            new OA\Property(property: 'settled_at', type: 'string', nullable: true, format: 'date-time'),
        ])),
    ],
)]
final class OccurrenceSplit {}
