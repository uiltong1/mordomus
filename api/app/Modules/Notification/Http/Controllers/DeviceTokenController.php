<?php

namespace Mordomus\Notification\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Notification\Contracts\Services\DeviceTokenServiceInterface;
use Mordomus\Notification\Http\Requests\DestroyDeviceTokenRequest;
use Mordomus\Notification\Http\Requests\IndexDeviceTokensRequest;
use Mordomus\Notification\Http\Requests\StoreDeviceTokenRequest;
use Mordomus\Notification\Http\Requests\TestDeviceTokenRequest;
use Mordomus\OpenApi\Schemas\DeviceToken as DeviceTokenSchema;
use Mordomus\OpenApi\Schemas\Error;
use OpenApi\Attributes as OA;

/**
 * Assinaturas de Web Push do morador (ADR-001).
 *
 * Registrar de novo a mesma assinatura atualiza a linha, e não cria outra: o
 * clique no sino pode acontecer quantas vezes o morador quiser, e duplicar a
 * assinatura faria o mesmo aviso sair duas vezes para o mesmo navegador.
 */
class DeviceTokenController extends Controller
{
    public function __construct(private readonly DeviceTokenServiceInterface $devices) {}

    #[OA\Get(
        path: '/api/v1/notification/devices',
        summary: 'Lista as assinaturas de push do morador',
        description: 'O recorte é o do chamador: a lista é dele. `p256dh` e `auth` não saem por serem segredo do navegador.',
        tags: ['notification'],
        security: [['jwtBearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Assinaturas registradas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: DeviceTokenSchema::class)),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexDeviceTokensRequest $request): JsonResponse
    {
        return response()->json($this->devices->index($request));
    }

    #[OA\Post(
        path: '/api/v1/notification/devices',
        summary: 'Registra (ou atualiza) a assinatura de push do morador',
        description: 'Idempotente por `endpoint`: registrar a mesma assinatura duas vezes devolve a mesma linha com `last_seen_at` novo.',
        tags: ['notification'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'platform', type: 'string', enum: ['web', 'android', 'ios'], default: 'web'),
            new OA\Property(property: 'endpoint', type: 'string', format: 'uri', maxLength: 2048, example: 'https://fcm.googleapis.com/fcm/send/abc123'),
            new OA\Property(property: 'p256dh', type: 'string', maxLength: 256, description: 'Chave pública do assinante, base64url'),
            new OA\Property(property: 'auth', type: 'string', maxLength: 64, description: 'Segredo de autenticação da assinatura, base64url'),
        ], required: ['endpoint', 'p256dh', 'auth'])),
        responses: [
            new OA\Response(response: 201, description: 'Assinatura registrada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: DeviceTokenSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Assinatura inválida: endpoint, `p256dh` ou `auth` fora do formato', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        return response()->json($this->devices->store($request), 201);
    }

    #[OA\Delete(
        path: '/api/v1/notification/devices',
        summary: 'Desinscreve a assinatura de push do morador',
        description: 'O alvo vem da query porque o `endpoint` é a chave natural da assinatura — é o que o navegador entrega e o que o serviço de push devolve quando ela morre.',
        tags: ['notification'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\Parameter(parameter: 'endpoint', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 2048))],
        responses: [
            new OA\Response(response: 200, description: 'Assinatura removida', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'removed', type: 'integer', example: 1),
                    new OA\Property(property: 'endpoint', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Assinatura não registrada para este morador', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Endpoint ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function destroy(DestroyDeviceTokenRequest $request): JsonResponse
    {
        return response()->json($this->devices->destroy($request));
    }

    #[OA\Post(
        path: '/api/v1/notification/devices/test',
        summary: 'Envia um aviso de teste para as assinaturas do morador',
        description: 'Síncrono de propósito: a tela precisa saber agora se a assinatura funciona. Resposta `503` quando o ambiente não tem par VAPID.',
        tags: ['notification'],
        security: [['jwtBearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Resultado por assinatura', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'devices', type: 'integer', example: 1),
                    new OA\Property(property: 'delivered', type: 'integer', example: 1),
                    new OA\Property(property: 'gone', type: 'integer', example: 0, description: 'Assinaturas que o serviço de push deu como mortas e que foram removidas'),
                    new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'string')),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 503, description: 'Ambiente sem par de chaves VAPID', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function test(TestDeviceTokenRequest $request): JsonResponse
    {
        return response()->json($this->devices->test($request));
    }
}
