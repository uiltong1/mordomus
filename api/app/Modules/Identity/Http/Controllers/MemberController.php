<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Http\Presenters\MemberPresenter;
use Mordomus\Identity\Http\Requests\UpdateMemberGrantsRequest;
use Mordomus\Identity\Http\Requests\UpdateMemberRequest;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Permission;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Services\CapabilityResolver;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\Member;
use OpenApi\Attributes as OA;

class MemberController extends Controller
{
    public function __construct(
        private readonly MemberPresenter $presenter,
        private readonly CapabilityResolver $capabilities,
    ) {}

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
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);

        $memberships = Membership::query()
            ->with(['user', 'role'])
            ->where('tenant_id', $tenant->id)
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $this->presenter->collection($memberships),
        ]);
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
        $denied = $this->authorizeChange($request, $tenant, $membership);

        if ($denied !== null) {
            return $denied;
        }

        if ($membership->user_id === $request->user()->id) {
            return $this->error($request, 422, 'cannot_change_own_role', 'Não é possível alterar a própria role.');
        }

        $membership->role_id = $request->input('role_id');
        $membership->save();
        $membership->load(['user', 'role']);
        $this->capabilities->forget($membership);

        return response()->json(['data' => $this->presenter->make($membership)]);
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
        $denied = $this->authorizeChange($request, $tenant, $membership);

        if ($denied !== null) {
            return $denied;
        }

        if ($membership->user_id === $request->user()->id) {
            return $this->error($request, 422, 'cannot_change_own_grants', 'Não é possível alterar os próprios grants.');
        }

        $permission = Permission::query()
            ->where('key', $request->input('capability'))
            ->firstOrFail();

        $membership->permissionGrants()->syncWithoutDetaching([
            $permission->id => ['granted' => $request->boolean('granted')],
        ]);

        $this->capabilities->forget($membership);
        $membership->load(['user', 'role']);

        return response()->json(['data' => $this->presenter->make($membership)]);
    }

    private function authorizeChange(Request $request, Tenant $tenant, Membership $membership): ?JsonResponse
    {
        $this->assertTenant($tenant, $request);

        abort_unless($request->user()->can('members.manage'), 403, 'forbidden');

        if ($membership->tenant_id !== $tenant->id) {
            return $this->error($request, 404, 'not_found', 'Membro não pertence à residência.');
        }

        return null;
    }
}
