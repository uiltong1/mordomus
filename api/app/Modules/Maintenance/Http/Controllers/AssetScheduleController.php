<?php

namespace Mordomus\Maintenance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Maintenance\Contracts\Services\AssetServiceInterface;
use Mordomus\Maintenance\Http\Requests\StoreAssetScheduleRequest;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\TriggerConfig as TriggerConfigSchema;
use OpenApi\Attributes as OA;

/**
 * Atalho do card do ativo para a regra de manutenção.
 *
 * Existe para o front não precisar montar `subject_type`/`subject_id` à mão:
 * a rota já diz que o alvo é este ativo, e o módulo Scheduling é quem cria a
 * regra e calcula a primeira data.
 */
class AssetScheduleController extends Controller
{
    public function __construct(private readonly AssetServiceInterface $assets) {}

    #[OA\Post(
        path: '/api/v1/maintenance/assets/{asset}/schedule',
        summary: 'Cria ou atualiza a regra de manutenção de um ativo',
        description: 'Mesmos parâmetros de `/scheduling/trigger-configs`, com o alvo fixado pela rota. Repetir o mesmo `title` atualiza a regra existente.',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'asset', description: 'Id ULID do ativo', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'title', type: 'string', maxLength: 160, example: 'Trocar o filtro do ar'),
            new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
            new OA\Property(property: 'is_active', type: 'boolean', default: true),
            new OA\Property(property: 'type', type: 'string', enum: ['INTERVAL', 'CALENDAR_MONTHLY', 'POST_COMPLETION', 'ESCALATED'], example: 'INTERVAL'),
            new OA\Property(property: 'interval_value', type: 'integer', minimum: 1, maximum: 65535),
            new OA\Property(property: 'interval_unit', type: 'string', enum: ['days', 'weeks', 'months']),
            new OA\Property(property: 'day_of_month', type: 'integer', minimum: 1, maximum: 31),
            new OA\Property(property: 'advance_notice_days', type: 'integer', minimum: 0, maximum: 365, default: 0),
            new OA\Property(property: 'recalculate_base', type: 'string', enum: ['DUE_DATE', 'COMPLETION']),
            new OA\Property(property: 'custom_offsets', type: 'array', items: new OA\Items(type: 'integer')),
            new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00'),
        ], required: ['title', 'type'])),
        responses: [
            new OA\Response(response: 201, description: 'Regra criada para o ativo', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: TriggerConfigSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability rules.edit ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Ativo não encontrado na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos; os campos do tipo aparecem em details', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreAssetScheduleRequest $request, string $asset): JsonResponse
    {
        return response()->json($this->assets->schedule($request, $asset), 201);
    }
}
