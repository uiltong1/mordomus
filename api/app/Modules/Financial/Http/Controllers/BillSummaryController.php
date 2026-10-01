<?php

namespace Mordomus\Financial\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Financial\Contracts\Services\BillSummaryServiceInterface;
use Mordomus\Financial\Http\Requests\SummaryBillRequest;
use Mordomus\Http\Controllers\Controller;
use Mordomus\OpenApi\Schemas\BillSummary as BillSummarySchema;
use Mordomus\OpenApi\Schemas\Error;
use OpenApi\Attributes as OA;

/**
 * Consolidação mensal: a conta da casa fecha em uma tela só.
 */
class BillSummaryController extends Controller
{
    public function __construct(private readonly BillSummaryServiceInterface $summary) {}

    #[OA\Get(
        path: '/api/v1/financial/summary',
        summary: 'Consolidação mensal dos vencimentos',
        description: 'Sem `month`, devolve o mês corrente da residência no fuso dela. Os valores são texto decimal com duas casas, porque a soma de centavo é o que fecha com o total.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\Parameter(parameter: 'month', in: 'query', schema: new OA\Schema(type: 'string', example: '2026-04'))],
        responses: [
            new OA\Response(response: 200, description: 'Consolidação do mês', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: BillSummarySchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Mês inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function summary(SummaryBillRequest $request): JsonResponse
    {
        return response()->json($this->summary->summary($request));
    }
}
