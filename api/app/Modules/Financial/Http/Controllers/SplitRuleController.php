<?php

namespace Mordomus\Financial\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Financial\Contracts\Services\SplitRuleServiceInterface;
use Mordomus\Financial\Http\Requests\IndexSplitRulesRequest;
use Mordomus\Financial\Http\Requests\UpdateSplitRuleRequest;
use Mordomus\Http\Controllers\Controller;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\SplitRule as SplitRuleSchema;
use OpenApi\Attributes as OA;

/**
 * Regras de divisão de cota-parte.
 *
 * A regra é identificada pela conta, e não por id: quem salva é a tela de uma
 * conta e ela sabe de qual conta está falando. `bill_id` ausente grava a regra
 * padrão da casa, que vale para tudo que não tem regra própria.
 */
class SplitRuleController extends Controller
{
    public function __construct(private readonly SplitRuleServiceInterface $rules) {}

    #[OA\Get(
        path: '/api/v1/financial/split-rules',
        summary: 'Lista as regras de divisão da residência',
        description: 'Leitura livre para qualquer morador ativo: a regra padrão da casa vem primeiro, e `bill_id` filtra pela conta.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'bill_id', in: 'query', description: 'Conta da regra; sem ele vem todas as regras da residência', schema: new OA\Schema(type: 'string', maxLength: 26)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Regras de divisão', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: SplitRuleSchema::class)),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Filtro inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexSplitRulesRequest $request): JsonResponse
    {
        return response()->json($this->rules->index($request));
    }

    #[OA\Put(
        path: '/api/v1/financial/split-rules',
        summary: 'Grava a regra de divisão de uma conta (ou a padrão da casa)',
        description: 'Upsert pela conta: `entries` é o conjunto completo de participantes, e salvar refaz as cotas dos vencimentos em aberto que a regra governa (R5).',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'bill_id', type: 'string', nullable: true, maxLength: 26, description: 'Conta da regra; ausente ou nulo grava a regra padrão da casa'),
            new OA\Property(property: 'mode', type: 'string', enum: ['EQUAL', 'WEIGHTED', 'PERCENT', 'CUSTOM'], example: 'PERCENT'),
            new OA\Property(property: 'is_active', type: 'boolean', description: 'Pausar a regra devolve a conta ao padrão da casa'),
            new OA\Property(property: 'entries', type: 'array', minItems: 1, maxItems: 50, items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'user_id', type: 'string', maxLength: 26),
                new OA\Property(property: 'weight', type: 'number', minimum: 0.01, maximum: 9999.99, nullable: true, example: 1.5, description: 'Exigido em `WEIGHTED` e proibido nos demais'),
                new OA\Property(property: 'percent', type: 'number', minimum: 0.01, maximum: 999.99, nullable: true, example: 40, description: 'Exigido em `PERCENT` e proibido nos demais; a soma é normalizada, então 30/30/30 é um terço para cada'),
                new OA\Property(property: 'fixed_amount', type: 'number', minimum: 0, maximum: 9999999999.99, nullable: true, example: 750, description: 'Exigido em `CUSTOM` e proibido nos demais'),
            ], required: ['user_id'])),
        ], required: ['mode', 'entries'])),
        responses: [
            new OA\Response(response: 200, description: 'Regra gravada e vencimentos em aberto recalculados', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: SplitRuleSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability splits.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Conta não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Regra inválida: regime desconhecido, morador fora da residência, campo do regime faltando ou de outro regime, ou regra que não fecha a conta', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function update(UpdateSplitRuleRequest $request): JsonResponse
    {
        return response()->json($this->rules->update($request));
    }
}
