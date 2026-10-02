<?php

namespace Mordomus\Notification\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Notification\Contracts\Services\NotificationLogServiceInterface;
use Mordomus\Notification\Http\Requests\IndexNotificationLogsRequest;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\NotificationLog;
use Mordomus\OpenApi\Schemas\PageMeta;
use OpenApi\Attributes as OA;

/**
 * Histórico do que o morador recebeu.
 *
 * Mostra também o que **falhou** e o motivo: é a resposta à pergunta "por que
 * não recebi?", e um histórico que só lista o que deu certo não responde nada
 * quando a pergunta existe.
 */
class NotificationLogController extends Controller
{
    public function __construct(private readonly NotificationLogServiceInterface $logs) {}

    #[OA\Get(
        path: '/api/v1/notification/logs',
        summary: 'Histórico de notificações do morador',
        description: 'O recorte é o do chamador. `status = queued` é a intenção esperando a janela de silêncio; `failed` é a DLQ do módulo com o motivo.',
        tags: ['notification'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(parameter: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
            new OA\Parameter(parameter: 'channel', in: 'query', schema: new OA\Schema(type: 'string', enum: ['push', 'email', 'inapp'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notificações paginadas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: NotificationLog::class)),
                new OA\Property(property: 'meta', ref: PageMeta::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Filtro inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexNotificationLogsRequest $request): JsonResponse
    {
        return response()->json($this->logs->index($request));
    }
}
