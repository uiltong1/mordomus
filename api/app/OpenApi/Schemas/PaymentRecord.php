<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaymentRecord',
    description: 'Baixa de pagamento de um vencimento; linha imutável de histórico',
    required: ['id', 'bill_occurrence_id', 'amount', 'method', 'paid_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D3'),
        new OA\Property(property: 'bill_occurrence_id', type: 'string'),
        new OA\Property(property: 'user_id', type: 'string', nullable: true, description: 'Quem pagou; nulo depois que a conta é removida da residência'),
        new OA\Property(property: 'amount', type: 'string', pattern: '^-?\d+\.\d{2}$', example: '187.43', description: 'Valor pago, que em conta variável é o único lugar onde o valor real aparece'),
        new OA\Property(property: 'method', type: 'string', enum: ['pix', 'boleto', 'debit_card', 'credit_card', 'cash', 'transfer', 'other']),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', example: '2026-04-10T12:00:00-03:00'),
        new OA\Property(property: 'receipt_url', type: 'string', nullable: true),
    ],
)]
final class PaymentRecord {}
