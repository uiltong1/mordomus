<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Contracts\Services\MemberServiceInterface;
use Mordomus\Identity\Http\Requests\IndexMembersRequest;
use Mordomus\Identity\Http\Requests\UpdateMemberGrantsRequest;
use Mordomus\Identity\Http\Requests\UpdateMemberRequest;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\Member;
use OpenApi\Attributes as OA;

class MemberController extends Controller
{
    public function __construct(private readonly MemberServiceInterface $service) {}

    #[OA\Get(
        path: '/api/v1/identity/tenants/{tenant}/members',
        summary: 'Lista os membros ativos da residência',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'tenant', description: 'Id ULID da residência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Membros com capabilities efetivas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: Member::class)),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Residência diferente da ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Residência não encontrada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexMembersRequest $request, Tenant $tenant): JsonResponse
    {
        return response()->json($this->service->index($request, $tenant));
    }

    #[OA\Patch(
        path: '/api/v1/identity/tenants/{tenant}/members/{membership}',
        summary: 'Troca o papel de um membro',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'tenant', description: 'Id ULID da residência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26)), new OA\PathParameter(parameter: 'membership', description: 'Id ULID do membership', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'role_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D3'),
        ], required: ['role_id'])),
        responses: [
            new OA\Response(response: 200, description: 'Membro atualizado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: Member::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Residência diferente da ativa ou capability members.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Membro ou residência não encontrados', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos ou tentativa de alterar a própria role', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function update(UpdateMemberRequest $request, Tenant $tenant, Membership $membership): JsonResponse
    {
        return response()->json($this->service->update($request, $tenant, $membership));
    }

    #[OA\Put(
        path: '/api/v1/identity/tenants/{tenant}/members/{membership}/grants',
        summary: 'Concede ou remove pontualmente uma capability do membro',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'tenant', description: 'Id ULID da residência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26)), new OA\PathParameter(parameter: 'membership', description: 'Id ULID do membership', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'capability', type: 'string', maxLength: 64, example: 'rules.edit'),
            new OA\Property(property: 'granted', type: 'boolean', example: true),
        ], required: ['capability', 'granted'])),
        responses: [
            new OA\Response(response: 200, description: 'Grant aplicado; capabilities recalculadas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: Member::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Residência diferente da ativa ou capability members.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Membro ou residência não encontrados', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos ou tentativa de alterar os próprios grants', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function updateGrants(UpdateMemberGrantsRequest $request, Tenant $tenant, Membership $membership): JsonResponse
    {
        return response()->json($this->service->updateGrants($request, $tenant, $membership));
    }
}
