<?php

namespace Mordomus\Financial\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Financial\Contracts\Services\BillOccurrenceServiceInterface;
use Mordomus\Financial\Http\Requests\IndexBillOccurrencesRequest;
use Mordomus\Financial\Http\Requests\PayBillOccurrenceRequest;
use Mordomus\Financial\Http\Requests\StoreBillOccurrenceRequest;
use Mordomus\Http\Controllers\Controller;
use Mordomus\OpenApi\Schemas\BillOccurrence as BillOccurrenceSchema;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\PageMeta;
use OpenApi\Attributes as OA;

/**
 * Vencimentos: consulta, lançamento manual e baixa de pagamento.
 *
 * O lançamento de uma conta com cadência não vem por esta rota: o Scheduling
 * materializa a data e o evento `schedule.occurrence.created` cria a linha
 * (regra R7). Aqui entra só o que a casa registra sozinha.
 */
class BillOccurrenceController extends Controller
{
    public function __construct(private readonly BillOccurrenceServiceInterface $occurrences) {}

    #[OA\Get(
        path: '/api/v1/financial/occurrences',
        summary: 'Lista os vencimentos da residência',
        description: 'O histórico filtra por tenant e por período; `month` traz o mês fechado e não combina com `from`/`to`.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(parameter: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
            new OA\Parameter(parameter: 'month', in: 'query', description: 'Mês fechado (`YYYY-MM`) no fuso da residência', schema: new OA\Schema(type: 'string', example: '2026-04')),
            new OA\Parameter(parameter: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-04-01')),
            new OA\Parameter(parameter: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-04-30')),
            new OA\Parameter(parameter: 'bill_id', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 26)),
            new OA\Parameter(parameter: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'paid', 'overdue', 'cancelled'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Vencimentos paginados', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: BillOccurrenceSchema::class)),
                new OA\Property(property: 'meta', ref: PageMeta::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Filtro inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexBillOccurrencesRequest $request): JsonResponse
    {
        return response()->json($this->occurrences->index($request));
    }

    #[OA\Post(
        path: '/api/v1/financial/occurrences',
        summary: 'Lança um vencimento manual',
        description: 'Caminho da conta variável: a data e o valor são os que o morador leu na fatura e vão como vieram, sem cálculo de data.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'bill_id', type: 'string', maxLength: 26, example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
            new OA\Property(property: 'due_date', type: 'string', format: 'date', example: '2026-04-10'),
            new OA\Property(property: 'amount', type: 'number', minimum: 0, example: 187.43),
        ], required: ['bill_id', 'due_date', 'amount'])),
        responses: [
            new OA\Response(response: 201, description: 'Vencimento lançado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: BillOccurrenceSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability bills.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Conta não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 409, description: 'Já existe vencimento desta conta nesta data', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreBillOccurrenceRequest $request): JsonResponse
    {
        return response()->json($this->occurrences->store($request), 201);
    }

    #[OA\Post(
        path: '/api/v1/financial/occurrences/{billOccurrence}/paid',
        summary: 'Baixa o pagamento de um vencimento',
        description: 'Idempotente: marcar duas vezes devolve o mesmo lançamento e não abre um segundo. Vencimento cancelado é 409.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'billOccurrence', description: 'Id ULID do vencimento', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'amount', type: 'number', minimum: 0, nullable: true, example: 187.43, description: 'Valor pago; sem ele vale o valor do vencimento'),
            new OA\Property(property: 'method', type: 'string', enum: ['pix', 'boleto', 'debit_card', 'credit_card', 'cash', 'transfer', 'other'], example: 'pix'),
            new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true, description: 'Instante do pagamento no fuso da residência; sem ele vale o momento da baixa'),
            new OA\Property(property: 'receipt_url', type: 'string', nullable: true, maxLength: 500),
        ], required: ['method'])),
        responses: [
            new OA\Response(response: 200, description: 'Vencimento quitado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: BillOccurrenceSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability bills.pay ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Vencimento não encontrado na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 409, description: 'Vencimento cancelado não recebe pagamento', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function pay(PayBillOccurrenceRequest $request, string $billOccurrence): JsonResponse
    {
        return response()->json($this->occurrences->pay($request, $billOccurrence));
    }
}
