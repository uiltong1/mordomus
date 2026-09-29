<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TriggerConfig',
    description: 'Regra de recorrência de um alvo (ativo ou conta), com a próxima data já calculada no fuso da residência',
    required: ['id', 'tenant_id', 'subject_type', 'subject_id', 'title', 'is_active', 'type', 'advance_notice_days'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        new OA\Property(property: 'tenant_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'),
        new OA\Property(property: 'subject_type', type: 'string', enum: ['asset', 'bill'], description: 'Alvo da regra'),
        new OA\Property(property: 'subject_id', type: 'string', description: 'Ativo ou conta alvo, conforme subject_type'),
        new OA\Property(property: 'title', type: 'string', maxLength: 160, example: 'Trocar o filtro do ar'),
        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 500),
        new OA\Property(property: 'is_active', type: 'boolean', example: true, description: 'Regra pausada não gera novas datas'),
        new OA\Property(property: 'type', type: 'string', enum: ['INTERVAL', 'CALENDAR_MONTHLY', 'POST_COMPLETION', 'ESCALATED']),
        new OA\Property(property: 'interval_value', type: 'integer', nullable: true, minimum: 1, maximum: 65535, description: 'Obrigatório em INTERVAL e POST_COMPLETION'),
        new OA\Property(property: 'interval_unit', type: 'string', nullable: true, enum: ['days', 'weeks', 'months']),
        new OA\Property(property: 'day_of_month', type: 'integer', nullable: true, minimum: 1, maximum: 31, description: 'Obrigatório em CALENDAR_MONTHLY; preso ao último dia em meses curtos'),
        new OA\Property(property: 'advance_notice_days', type: 'integer', minimum: 0, maximum: 365, description: ' antecedência do aviso, em dias'),
        new OA\Property(property: 'recalculate_base', type: 'string', nullable: true, enum: ['DUE_DATE', 'COMPLETION'], description: 'Obrigatório em POST_COMPLETION'),
        new OA\Property(property: 'custom_offsets', type: 'array', nullable: true, description: 'Obrigatório em ESCALATED; negativo avisa antes, positivo atrasado', items: new OA\Items(type: 'integer')),
        new OA\Property(property: 'preferred_hour', type: 'string', nullable: true, example: '09:00', description: 'HH:MM; sem ela vale a da residência'),
        new OA\Property(property: 'last_base_date', type: 'string', nullable: true, format: 'date', description: 'Âncora do ciclo corrente'),
        new OA\Property(property: 'next_due_at', type: 'string', nullable: true, format: 'date-time', example: '2026-11-24T12:00:00Z'),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class TriggerConfig {}
