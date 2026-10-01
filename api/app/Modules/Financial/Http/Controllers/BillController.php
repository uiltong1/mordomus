<?php

namespace Mordomus\Financial\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Financial\Contracts\Services\BillServiceInterface;
use Mordomus\Financial\Http\Requests\IndexBillsRequest;
use Mordomus\Financial\Http\Requests\ShowBillRequest;
use Mordomus\Financial\Http\Requests\StoreBillRequest;
use Mordomus\Financial\Http\Requests\UpdateBillRequest;
use Mordomus\Http\Controllers\Controller;
use Mordomus\OpenApi\Schemas\Bill as BillSchema;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\PageMeta;
use OpenApi\Attributes as OA;

/**
 * Cadastro das contas da residência.
 *
 * `due_day` e `advance_notice_days` não descrevem a conta: descrevem a
 * cadência, que o módulo Scheduling escreve e é quem calcula as datas. O que a
 * conta guarda é o que a casa paga.
 */
class BillController extends Controller
{
    public function __construct(private readonly BillServiceInterface $bills) {}

    #[OA\Get(
        path: '/api/v1/financial/bills',
        summary: 'Lista as contas da residência ativa',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(parameter: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
            new OA\Parameter(parameter: 'kind', in: 'query', schema: new OA\Schema(type: 'string', enum: ['fixed', 'variable'])),
            new OA\Parameter(parameter: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(parameter: 'category', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 40)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contas paginadas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: BillSchema::class)),
                new OA\Property(property: 'meta', ref: PageMeta::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Filtro inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexBillsRequest $request): JsonResponse
    {
        return response()->json($this->bills->index($request));
    }

    #[OA\Get(
        path: '/api/v1/financial/bills/{bill}',
        summary: 'Detalhe de uma conta, com a cadência que ela pediu',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'bill', description: 'Id ULID da conta', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Conta', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: BillSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Conta não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function show(ShowBillRequest $request, string $bill): JsonResponse
    {
        return response()->json($this->bills->show($request, $bill));
    }

    #[OA\Post(
        path: '/api/v1/financial/bills',
        summary: 'Cadastra uma conta',
        description: 'Conta com `due_day` recebe uma regra `CALENDAR_MONTHLY` no módulo Scheduling, que calcula a primeira data e materializa os vencimentos. Sem `due_day`, a conta fica sem cadência e o vencimento é lançado à mão.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Conta de luz'),
            new OA\Property(property: 'kind', type: 'string', enum: ['fixed', 'variable'], example: 'fixed'),
            new OA\Property(property: 'category', type: 'string', maxLength: 40, nullable: true, example: 'energia'),
            new OA\Property(property: 'amount', type: 'number', minimum: 0, nullable: true, example: 187.43, description: 'Valor previsto; em conta variável é omitido e entra na baixa de pagamento'),
            new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, default: 'BRL'),
            new OA\Property(property: 'is_active', type: 'boolean', default: true),
            new OA\Property(property: 'due_day', type: 'integer', minimum: 1, maximum: 31, nullable: true, example: 10, description: 'Dia do vencimento mensal'),
            new OA\Property(property: 'advance_notice_days', type: 'integer', minimum: 0, maximum: 365, example: 3, description: 'Aviso quantos dias antes do vencimento'),
        ], required: ['name', 'kind'])),
        responses: [
            new OA\Response(response: 201, description: 'Conta criada com a primeira data calculada pelo Scheduling', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: BillSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability bills.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreBillRequest $request): JsonResponse
    {
        return response()->json($this->bills->store($request), 201);
    }

    #[OA\Patch(
        path: '/api/v1/financial/bills/{bill}',
        summary: 'Atualiza uma conta',
        description: 'Enviar `due_day` reescreve a cadência da conta; `due_day: null` a remove sem apagar o histórico. Desativar a conta cancela o que ainda não venceu e mantém em aberto o que já venceu.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'bill', description: 'Id ULID da conta', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 120),
            new OA\Property(property: 'kind', type: 'string', enum: ['fixed', 'variable']),
            new OA\Property(property: 'category', type: 'string', maxLength: 40, nullable: true),
            new OA\Property(property: 'amount', type: 'number', minimum: 0, nullable: true),
            new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3),
            new OA\Property(property: 'is_active', type: 'boolean'),
            new OA\Property(property: 'due_day', type: 'integer', minimum: 1, maximum: 31, nullable: true),
            new OA\Property(property: 'advance_notice_days', type: 'integer', minimum: 0, maximum: 365),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Conta atualizada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: BillSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability bills.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Conta não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function update(UpdateBillRequest $request, string $bill): JsonResponse
    {
        return response()->json($this->bills->update($request, $bill));
    }
}
