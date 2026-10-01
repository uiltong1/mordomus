<?php

namespace Mordomus\Financial\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Financial\Contracts\Services\SplitResultServiceInterface;
use Mordomus\Financial\Http\Requests\SettleSplitRequest;
use Mordomus\Financial\Http\Requests\ShowSplitRequest;
use Mordomus\Http\Controllers\Controller;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\OccurrenceSplit;
use OpenApi\Attributes as OA;

/**
 * Cota-parte de um vencimento: quanto cabe a cada morador e quem já quitou.
 *
 * A leitura responde a duas perguntas diferentes com o mesmo endpoint: quem
 * administra a divisão vê a conta inteira, e quem só tem `splits.view_own` vê
 * a cota dele. A gravação é a baixa da cota, e a capability dela é a de
 * gerenciar a divisão — é quem responde pela conta que anota a quitação.
 */
class SplitController extends Controller
{
    public function __construct(private readonly SplitResultServiceInterface $splits) {}

    #[OA\Get(
        path: '/api/v1/financial/occurrences/{billOccurrence}/split',
        summary: 'Cota-parte calculada de um vencimento',
        description: 'A soma das cotas é o valor do vencimento (R5). Vencimento sem regra ativa na residência é 404; vencimento ainda não materializado responde pelo cálculo, sem gravar.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'billOccurrence', description: 'Id ULID do vencimento', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Divisão do vencimento', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: OccurrenceSplit::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem `splits.manage` nem `splits.view_own`', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Vencimento fora da residência ativa, ou conta sem regra de divisão ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Regra que não fecha a conta: participantes vazios, pesos ou percentuais de soma zero, ou cotas fechadas acima do total', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function show(ShowSplitRequest $request, string $billOccurrence): JsonResponse
    {
        return response()->json($this->splits->show($request, $billOccurrence));
    }

    #[OA\Patch(
        path: '/api/v1/financial/occurrences/{billOccurrence}/split',
        summary: 'Baixa (ou desfaz) a cota de um morador',
        description: 'Idempotente: repetir a baixa devolve a mesma linha e o instante da primeira continua valendo. `settled: false` desfaz, para quando a casa anotou errado.',
        tags: ['financial'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'billOccurrence', description: 'Id ULID do vencimento', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'user_id', type: 'string', maxLength: 26, description: 'Morador da cota'),
            new OA\Property(property: 'settled', type: 'boolean', default: true, description: 'Omitido vale `true`; `false` desfaz a baixa'),
        ], required: ['user_id'])),
        responses: [
            new OA\Response(response: 200, description: 'Cota baixada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: OccurrenceSplit::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability splits.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Vencimento fora da residência ativa, ou morador sem cota nele', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function settle(SettleSplitRequest $request, string $billOccurrence): JsonResponse
    {
        return response()->json($this->splits->settle($request, $billOccurrence));
    }
}
