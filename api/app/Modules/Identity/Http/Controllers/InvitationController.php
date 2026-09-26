<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Contracts\Services\InvitationServiceInterface;
use Mordomus\Identity\Http\Requests\AcceptInvitationRequest;
use Mordomus\Identity\Http\Requests\StoreInvitationRequest;
use Mordomus\Identity\Models\Tenant;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\Invitation as InvitationSchema;
use Mordomus\OpenApi\Schemas\TenantSummary;
use OpenApi\Attributes as OA;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationServiceInterface $service) {}

    #[OA\Post(
        path: '/api/v1/identity/tenants/{tenant}/invitations',
        summary: 'Convida uma pessoa por e-mail',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\PathParameter(parameter: 'tenant', description: 'Id ULID da residência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'convidada@mordomus.test'),
            new OA\Property(property: 'role_id', type: 'string', description: 'Papel concedido; sem ele o padrão é morador'),
        ], required: ['email'])),
        responses: [
            new OA\Response(response: 201, description: 'Convite criado; um convite pendente anterior do mesmo e-mail é substituído', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: InvitationSchema::class),
                new OA\Property(property: 'token', type: 'string', description: 'Token opaco entregue na resposta enquanto não há envio por e-mail'),
                new OA\Property(property: 'accept_path', type: 'string', example: '/invitations/{token}/accept'),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Residência diferente da ativa ou capability members.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Residência não encontrada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreInvitationRequest $request, Tenant $tenant): JsonResponse
    {
        return response()->json($this->service->store($request, $tenant), 201);
    }

    #[OA\Post(
        path: '/api/v1/identity/invitations/{token}/accept',
        summary: 'Aceita o convite e entra na residência',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\PathParameter(parameter: 'token', description: 'Token opaco recebido no convite', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Membership criado e sessão apontando para a residência', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'token_type', type: 'string', example: 'bearer'),
                new OA\Property(property: 'access_token', type: 'string'),
                new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
                new OA\Property(property: 'active_tenant', type: 'string', nullable: true),
                new OA\Property(property: 'refresh_token', type: 'string'),
                new OA\Property(property: 'tenant', type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string'),
                    new OA\Property(property: 'role', type: 'string', example: 'member'),
                ]),
                new OA\Property(property: 'user', type: 'object', required: ['id', 'name', 'email'], properties: [
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                ]),
                new OA\Property(property: 'tenants', type: 'array', items: new OA\Items(ref: TenantSummary::class)),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Convite pertence a outro e-mail', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Convite não encontrado', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 409, description: 'Convite já aceito', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 410, description: 'Convite expirado', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        return response()->json($this->service->accept($request, $token));
    }
}
