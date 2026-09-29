<?php

namespace Mordomus\Scheduling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\Occurrence as OccurrenceSchema;
use Mordomus\OpenApi\Schemas\PageMeta;
use Mordomus\Scheduling\Contracts\Services\OccurrenceServiceInterface;
use Mordomus\Scheduling\Http\Requests\CompleteOccurrenceRequest;
use Mordomus\Scheduling\Http\Requests\IndexOccurrencesRequest;
use Mordomus\Scheduling\Http\Requests\SkipOccurrenceRequest;
use OpenApi\Attributes as OA;

/**
 * Agenda materializada pelo motor: consulta, check-in e dispensa.
 *
 * A conclusão é idempotente por contrato: repetir o check-in devolve a mesma
 * ocorrência e não abre um segundo ciclo. Recalcular a data é trabalho da
 * fila, então a resposta não carrega a próxima data — quem precisa dela ouve
 * `occurrence.completed`, ou lê a regra.
 */
class OccurrenceController extends Controller
{
    public function __construct(private readonly OccurrenceServiceInterface $occurrences) {}

    #[OA\Get(
        path: '/api/v1/scheduling/occurrences',
        summary: 'Lista as ocorrências da residência ativa',
        description: 'Ordena por `due_at`. `from`/`to` são dias de calendário no fuso da residência e `to` é inclusivo; sem eles a agenda inteira é devolvida, paginada.',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(parameter: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
            new OA\Parameter(parameter: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-10-01')),
            new OA\Parameter(parameter: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-10-31')),
            new OA\Parameter(parameter: 'subject_type', in: 'query', description: 'Filtra por tipo de alvo', schema: new OA\Schema(type: 'string', enum: ['asset', 'bill'])),
            new OA\Parameter(parameter: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'notified', 'completed', 'skipped', 'overdue'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ocorrências paginadas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: OccurrenceSchema::class)),
                new OA\Property(property: 'meta', ref: PageMeta::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Filtro inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexOccurrencesRequest $request): JsonResponse
    {
        return response()->json($this->occurrences->index($request));
    }

    #[OA\Post(
        path: '/api/v1/scheduling/occurrences/{occurrence}/complete',
        summary: 'Conclui uma ocorrência e recalcula o ciclo',
        description: 'Idempotente: concluir de novo devolve a mesma ocorrência sem abrir um segundo ciclo. O recálculo roda na fila e publica `occurrence.completed` com a próxima data.',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'occurrence', description: 'Id ULID da ocorrência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Ocorrência concluída; a próxima data vem no evento `occurrence.completed`', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: OccurrenceSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability occurrences.complete ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Ocorrência não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 409, description: 'Ocorrência já dispensada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function complete(CompleteOccurrenceRequest $request, string $occurrence): JsonResponse
    {
        return response()->json($this->occurrences->complete($request, $occurrence));
    }

    #[OA\Post(
        path: '/api/v1/scheduling/occurrences/{occurrence}/skip',
        summary: 'Dispensa uma ocorrência sem concluir o ciclo',
        description: 'A linha vira `skipped` e permanece como histórico. O ciclo não é recalculado: pular diz que aquele dia não acontece, e a próxima data continua a que a regra já tinha.',
        tags: ['scheduling'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'occurrence', description: 'Id ULID da ocorrência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Ocorrência dispensada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: OccurrenceSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability occurrences.skip ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Ocorrência não encontrada na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 409, description: 'Ocorrência já concluída', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function skip(SkipOccurrenceRequest $request, string $occurrence): JsonResponse
    {
        return response()->json($this->occurrences->skip($request, $occurrence));
    }
}
