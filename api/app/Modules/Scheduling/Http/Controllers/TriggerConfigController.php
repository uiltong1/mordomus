<?php

namespace Mordomus\Scheduling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\PageMeta;
use Mordomus\OpenApi\Schemas\TriggerConfig as TriggerConfigSchema;
use Mordomus\Scheduling\Contracts\Services\TriggerConfigServiceInterface;
use Mordomus\Scheduling\Http\Requests\DestroyTriggerConfigRequest;
use Mordomus\Scheduling\Http\Requests\IndexTriggerConfigsRequest;
use Mordomus\Scheduling\Http\Requests\PreviewNextDueRequest;
use Mordomus\Scheduling\Http\Requests\ShowTriggerConfigRequest;
use Mordomus\Scheduling\Http\Requests\StoreTriggerConfigRequest;
use Mordomus\Scheduling\Http\Requests\UpdateTriggerConfigRequest;
use OpenApi\Attributes as OA;

/**
 * Regras de recorrência da residência: CRUD, filtro por alvo e o cálculo de
 * preview.
 *
 * O alvo vem como `subject_type` + `subject_id` na URL/payload e vira a coluna
 * (`asset_id`/`bill_id`) que o banco restringe com FK — a existência do alvo é
 * garantida pela restrição, não por chamada a outro módulo.
 */
class TriggerConfigController extends Controller
{
    public function __construct(private readonly TriggerConfigServiceInterface $configs) {}

    #[OA\Get(
        path: '/api/v1/scheduling/trigger-configs',
        summary: 'Lista as regras de recorrência da residência ativa',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(parameter: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
            new OA\Parameter(parameter: 'subject_type', in: 'query', description: 'Filtra por tipo de alvo', schema: new OA\Schema(type: 'string', enum: ['asset', 'bill'])),
            new OA\Parameter(parameter: 'subject_id', in: 'query', description: 'Filtra por alvo; exige subject_type', schema: new OA\Schema(type: 'string', maxLength: 26)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Regras paginadas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: TriggerConfigSchema::class)),
                new OA\Property(property: 'meta', ref: PageMeta::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Filtro inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexTriggerConfigsRequest $request): JsonResponse
    {
        return response()->json($this->configs->index($request));
    }

    #[OA\Get(
        path: '/api/v1/scheduling/trigger-configs/{triggerConfig}',
        summary: 'Detalhe de uma regra de recorrência',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'triggerConfig', description: 'Id ULID da regra', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Regra', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: TriggerConfigSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Regra não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function show(ShowTriggerConfigRequest $request, string $triggerConfig): JsonResponse
    {
        return response()->json($this->configs->show($request, $triggerConfig));
    }

    #[OA\Post(
        path: '/api/v1/scheduling/trigger-configs',
        summary: 'Cria uma regra de recorrência para um alvo',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'subject_type', type: 'string', enum: ['asset', 'bill'], example: 'asset'),
            new OA\Property(property: 'subject_id', type: 'string', maxLength: 26, description: 'Ativo ou conta alvo'),
            new OA\Property(property: 'title', type: 'string', maxLength: 160, example: 'Trocar o filtro do ar'),
            new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
            new OA\Property(property: 'is_active', type: 'boolean', default: true),
            new OA\Property(property: 'type', type: 'string', enum: ['INTERVAL', 'CALENDAR_MONTHLY', 'POST_COMPLETION', 'ESCALATED'], example: 'INTERVAL'),
            new OA\Property(property: 'interval_value', type: 'integer', minimum: 1, maximum: 65535, example: 90, description: 'Obrigatório em INTERVAL e POST_COMPLETION'),
            new OA\Property(property: 'interval_unit', type: 'string', enum: ['days', 'weeks', 'months'], example: 'days'),
            new OA\Property(property: 'day_of_month', type: 'integer', minimum: 1, maximum: 31, description: 'Obrigatório em CALENDAR_MONTHLY'),
            new OA\Property(property: 'advance_notice_days', type: 'integer', minimum: 0, maximum: 365, default: 0),
            new OA\Property(property: 'recalculate_base', type: 'string', enum: ['DUE_DATE', 'COMPLETION'], description: 'Obrigatório em POST_COMPLETION'),
            new OA\Property(property: 'custom_offsets', type: 'array', description: 'Obrigatório em ESCALATED; ex.: [-7,-3,0,1]', items: new OA\Items(type: 'integer')),
            new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00', description: 'HH:MM; sem ela vale a da residência'),
        ], required: ['subject_type', 'subject_id', 'title', 'type'])),
        responses: [
            new OA\Response(response: 201, description: 'Regra criada com a próxima data calculada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: TriggerConfigSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability rules.edit ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 409, description: 'Já existe regra com este título para o mesmo alvo', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos; os campos do tipo aparecem em details', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreTriggerConfigRequest $request): JsonResponse
    {
        return response()->json($this->configs->store(
            $request,
            $request->string('subject_type')->toString(),
            $request->string('subject_id')->toString(),
        ), 201);
    }

    #[OA\Patch(
        path: '/api/v1/scheduling/trigger-configs/{triggerConfig}',
        summary: 'Atualiza uma regra de recorrência',
        description: 'Trocar o `type` exige o conjunto de campos do tipo novo e limpa os que sobraram.',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'triggerConfig', description: 'Id ULID da regra', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['INTERVAL', 'CALENDAR_MONTHLY', 'POST_COMPLETION', 'ESCALATED']),
            new OA\Property(property: 'title', type: 'string', maxLength: 160),
            new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
            new OA\Property(property: 'is_active', type: 'boolean'),
            new OA\Property(property: 'interval_value', type: 'integer', minimum: 1, maximum: 65535),
            new OA\Property(property: 'interval_unit', type: 'string', enum: ['days', 'weeks', 'months']),
            new OA\Property(property: 'day_of_month', type: 'integer', minimum: 1, maximum: 31),
            new OA\Property(property: 'advance_notice_days', type: 'integer', minimum: 0, maximum: 365),
            new OA\Property(property: 'recalculate_base', type: 'string', enum: ['DUE_DATE', 'COMPLETION']),
            new OA\Property(property: 'custom_offsets', type: 'array', items: new OA\Items(type: 'integer')),
            new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Regra atualizada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: TriggerConfigSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability rules.edit ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Regra não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos; os campos do tipo aparecem em details', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function update(UpdateTriggerConfigRequest $request, string $triggerConfig): JsonResponse
    {
        return response()->json($this->configs->update($request, $triggerConfig));
    }

    #[OA\Delete(
        path: '/api/v1/scheduling/trigger-configs/{triggerConfig}',
        summary: 'Exclui uma regra de recorrência',
        description: 'Exclusão de verdade: as ocorrências e a trilha da regra vão junto, em cascata. Para pausar sem perder o histórico, use `is_active = false`.',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'triggerConfig', description: 'Id ULID da regra', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Regra excluída', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: TriggerConfigSchema::class),
                new OA\Property(property: 'deleted', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability rules.edit ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Regra não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function destroy(DestroyTriggerConfigRequest $request, string $triggerConfig): JsonResponse
    {
        return response()->json($this->configs->destroy($request, $triggerConfig));
    }

    #[OA\Post(
        path: '/api/v1/scheduling/preview',
        summary: 'Calcula a próxima data sem persistir',
        description: 'Não pede alvo: a matemática da data não depende do ativo nem da conta. `base` simula a âncora do ciclo.',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['INTERVAL', 'CALENDAR_MONTHLY', 'POST_COMPLETION', 'ESCALATED'], example: 'CALENDAR_MONTHLY'),
            new OA\Property(property: 'interval_value', type: 'integer', minimum: 1, maximum: 65535),
            new OA\Property(property: 'interval_unit', type: 'string', enum: ['days', 'weeks', 'months']),
            new OA\Property(property: 'day_of_month', type: 'integer', minimum: 1, maximum: 31),
            new OA\Property(property: 'recalculate_base', type: 'string', enum: ['DUE_DATE', 'COMPLETION']),
            new OA\Property(property: 'custom_offsets', type: 'array', items: new OA\Items(type: 'integer')),
            new OA\Property(property: 'base', type: 'string', format: 'date', nullable: true, example: '2026-01-15'),
            new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00'),
        ], required: ['type'])),
        responses: [
            new OA\Response(response: 200, description: 'Data calculada; `next_due_at` é nulo em POST_COMPLETION/ESCALATED sem `base`', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['INTERVAL', 'CALENDAR_MONTHLY', 'POST_COMPLETION', 'ESCALATED']),
                    new OA\Property(property: 'scheduled_for', type: 'string', nullable: true, format: 'date', example: '2026-10-15'),
                    new OA\Property(property: 'next_due_at', type: 'string', nullable: true, format: 'date-time', example: '2026-10-15T12:00:00Z'),
                    new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00'),
                    new OA\Property(property: 'timezone', type: 'string', example: 'America/Sao_Paulo'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos; os campos do tipo aparecem em details', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function preview(PreviewNextDueRequest $request): JsonResponse
    {
        return response()->json($this->configs->preview($request));
    }
}
