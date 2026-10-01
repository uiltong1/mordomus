<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Bill',
    description: 'Conta da residência; a cadência é a regra do Scheduling lida por `schedule`',
    required: ['id', 'tenant_id', 'name', 'kind', 'currency', 'is_active'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'tenant_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'),
        new OA\Property(property: 'name', type: 'string', example: 'Conta de luz'),
        new OA\Property(property: 'kind', type: 'string', enum: ['fixed', 'variable'], description: 'fixed tem valor e vencimento conhecidos; variable tem valor que só existe na fatura'),
        new OA\Property(property: 'category', type: 'string', nullable: true, example: 'energia'),
        new OA\Property(property: 'amount', type: 'string', nullable: true, pattern: '^-?\d+\.\d{2}$', example: '187.43', description: 'Texto decimal com duas casas; nulo quando o valor só existe na fatura'),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, example: 'BRL'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'schedule', nullable: true, description: 'Cadência viva da conta, escrita pelo módulo Scheduling; nula quando a conta não pediu vencimento automático', properties: [
            new OA\Property(property: 'id', type: 'string', description: 'Regra de recorrência'),
            new OA\Property(property: 'type', type: 'string', enum: ['CALENDAR_MONTHLY']),
            new OA\Property(property: 'day_of_month', type: 'integer', minimum: 1, maximum: 31, example: 10),
            new OA\Property(property: 'advance_notice_days', type: 'integer', minimum: 0, maximum: 365, example: 3),
            new OA\Property(property: 'preferred_hour', type: 'string', nullable: true, example: '09:00'),
            new OA\Property(property: 'is_active', type: 'boolean'),
            new OA\Property(property: 'next_due_at', type: 'string', nullable: true, format: 'date-time', description: 'Próxima data calculada pelo Scheduling (R7)'),
        ]),
        new OA\Property(property: 'created_by', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class Bill {}
