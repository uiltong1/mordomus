<?php

namespace Mordomus\Maintenance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Maintenance\Contracts\Services\AssetServiceInterface;
use Mordomus\Maintenance\Http\Requests\CompleteAssetOccurrenceRequest;
use Mordomus\Maintenance\Http\Requests\SkipAssetOccurrenceRequest;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\Occurrence;
use OpenApi\Attributes as OA;

/**
 * Check-in e dispensa pelo card do ativo.
 *
 * Mesmo contrato de `/scheduling/occurrences/{id}/complete` e `/skip`, com o
 * alvo travado em `asset`: o card do ativo não conclui a ocorrência de uma
 * conta. A ocorrência e o recálculo do ciclo são do módulo Scheduling.
 */
class AssetOccurrenceController extends Controller
{
    public function __construct(private readonly AssetServiceInterface $assets) {}

    #[OA\Post(
        path: '/api/v1/maintenance/occurrences/{occurrence}/complete',
        summary: 'Check-in de conclusão de uma ocorrência de ativo',
        description: 'Delega ao módulo Scheduling, que é dono da ocorrência e do recálculo do ciclo. Idempotente.',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'occurrence', description: 'Id ULID da ocorrência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Ocorrência concluída', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: Occurrence::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability occurrences.complete ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Ocorrência de ativo não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 409, description: 'Ocorrência já dispensada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function complete(CompleteAssetOccurrenceRequest $request, string $occurrence): JsonResponse
    {
        return response()->json($this->assets->completeOccurrence($request, $occurrence));
    }

    #[OA\Post(
        path: '/api/v1/maintenance/occurrences/{occurrence}/skip',
        summary: 'Dispensa uma ocorrência de ativo',
        description: 'Delega ao módulo Scheduling. A linha vira `skipped` e permanece como histórico; o ciclo não é recalculado.',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'occurrence', description: 'Id ULID da ocorrência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Ocorrência dispensada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: Occurrence::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability occurrences.skip ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Ocorrência de ativo não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 409, description: 'Ocorrência já concluída', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function skip(SkipAssetOccurrenceRequest $request, string $occurrence): JsonResponse
    {
        return response()->json($this->assets->skipOccurrence($request, $occurrence));
    }
}
