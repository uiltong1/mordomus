<?php

namespace Mordomus\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Occurrence',
    description: 'Ocorrência materializada de uma regra: dia do calendário, instante de disparo e situação no ciclo',
    required: ['id', 'tenant_id', 'trigger_config_id', 'subject_type', 'subject_id', 'title', 'scheduled_for', 'due_at', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D2'),
        new OA\Property(property: 'tenant_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'),
        new OA\Property(property: 'trigger_config_id', type: 'string', description: 'Regra que gerou a ocorrência'),
        new OA\Property(property: 'subject_type', type: 'string', enum: ['asset', 'bill'], description: 'Alvo da regra; sempre presente — a FK leva a ocorrência junto na cascata'),
        new OA\Property(property: 'subject_id', type: 'string', description: 'Ativo ou conta alvo, conforme subject_type'),
        new OA\Property(property: 'title', type: 'string', example: 'Trocar o filtro do ar'),
        new OA\Property(property: 'scheduled_for', type: 'string', format: 'date', example: '2026-10-15', description: 'Dia do calendário a que a ocorrência pertence'),
        new OA\Property(property: 'due_at', type: 'string', format: 'date-time', example: '2026-10-15T12:00:00Z', description: 'Instante de disparo, na hora preferida da residência'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'notified', 'completed', 'skipped', 'overdue'], description: 'overdue é constatação de atraso e ainda aceita check-in'),
        new OA\Property(property: 'notified_at', type: 'string', nullable: true, format: 'date-time', description: 'Primeiro aviso publicado para esta ocorrência'),
        new OA\Property(property: 'completed_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'completed_by', type: 'string', nullable: true, description: 'Morador que fechou o ciclo; nulo para ocorrência gerada pela fila'),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', nullable: true, format: 'date-time'),
    ],
)]
final class Occurrence {}
