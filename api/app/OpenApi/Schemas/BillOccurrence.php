<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BillOccurrence',
    description: 'Vencimento de conta: um dia a pagar, com o histórico do que o quitou',
    required: ['id', 'tenant_id', 'bill_id', 'due_date', 'amount', 'status', 'payments'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D4'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'bill_id', type: 'string'),
        new OA\Property(property: 'bill_name', type: 'string', nullable: true, example: 'Conta de luz'),
        new OA\Property(property: 'bill_kind', type: 'string', nullable: true, enum: ['fixed', 'variable']),
        new OA\Property(property: 'category', type: 'string', nullable: true, example: 'energia'),
        new OA\Property(property: 'schedule_id', type: 'string', nullable: true, description: 'Ocorrência do Scheduling que gerou o vencimento; nulo no lançamento manual'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', example: '2026-04-10', description: 'Dia de calendário no fuso da residência'),
        new OA\Property(property: 'amount', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '187.43', description: 'Valor do vencimento; em conta variável começa em 0.00 e passa a valer o que foi pago'),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'paid', 'overdue', 'cancelled']),
        new OA\Property(property: 'paid_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'paid_by', type: 'string', nullable: true),
        new OA\Property(property: 'payments', type: 'array', description: 'Baixas que quitam o vencimento, da mais antiga para a mais nova', items: new OA\Items(ref: PaymentRecord::class)),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class BillOccurrence {}
