<?php

namespace Mordomus\Notification\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Notification\Contracts\Services\NotificationPreferenceServiceInterface;
use Mordomus\Notification\Http\Requests\ShowNotificationPreferenceRequest;
use Mordomus\Notification\Http\Requests\UpdateNotificationPreferenceRequest;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\NotificationPreference as NotificationPreferenceSchema;
use OpenApi\Attributes as OA;

/**
 * Quiet hours, digest e horário preferido.
 *
 * A resposta traz as três camadas — a do morador, a da casa e a que vale — e o
 * PUT grava só a primeira. Quem escreve aqui nunca mexe no padrão da casa, que
 * é decisão de quem administra a residência.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceServiceInterface $preferences,
    ) {}

    #[OA\Get(
        path: '/api/v1/notification/preferences',
        summary: 'Preferências de notificação do morador',
        description: 'Devolve o que o morador configurou, o padrão da casa e a precedência resolvida. `quiet_window_wraps_midnight` diz se a janela atravessa a meia-noite.',
        tags: ['notification'],
        security: [['jwtBearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Preferências', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: NotificationPreferenceSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function show(ShowNotificationPreferenceRequest $request): JsonResponse
    {
        return response()->json($this->preferences->show($request));
    }

    #[OA\Put(
        path: '/api/v1/notification/preferences',
        summary: 'Grava a preferência de notificação do morador',
        description: 'Substitui o conjunto e grava só a linha do morador. Janela com um lado só é `422`: meia janela não existe.',
        tags: ['notification'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'quiet_start', type: 'string', nullable: true, pattern: '^\d{2}:\d{2}$', example: '22:00'),
            new OA\Property(property: 'quiet_end', type: 'string', nullable: true, pattern: '^\d{2}:\d{2}$', example: '07:00'),
            new OA\Property(property: 'digest', type: 'string', nullable: true, enum: ['instant', 'daily'], description: '`instant` entrega por push assim que pode; `daily` entrega por e-mail no horário preferido'),
            new OA\Property(property: 'preferred_hour', type: 'string', nullable: true, pattern: '^\d{2}:\d{2}$', example: '09:00'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Preferência gravada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: NotificationPreferenceSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Horário inválido, digest desconhecido ou janela com um lado só', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function update(UpdateNotificationPreferenceRequest $request): JsonResponse
    {
        return response()->json($this->preferences->update($request));
    }
}
