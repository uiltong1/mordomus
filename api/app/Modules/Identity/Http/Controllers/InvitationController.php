<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Http\Presenters\UserPresenter;
use Mordomus\Identity\Http\Requests\StoreInvitationRequest;
use Mordomus\Identity\Models\Invitation;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\InvitationToken;
use Mordomus\Identity\Services\TokenPackager;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\Invitation as InvitationSchema;
use Mordomus\OpenApi\Schemas\TenantSummary;
use OpenApi\Attributes as OA;

class InvitationController extends Controller
{
    public function __construct(
        private readonly TokenPackager $tokens,
        private readonly UserPresenter $userPresenter,
    ) {}

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
        $this->assertTenant($tenant, $request);

        abort_unless($request->user()->can('members.manage'), 403, 'forbidden');

        $role = $request->filled('role_id')
            ? Role::query()->findOrFail($request->input('role_id'))
            : Role::systemByKey(Role::MEMBER);

        $token = InvitationToken::generate();

        $invitation = DB::transaction(function () use ($request, $tenant, $role, $token) {
            // um convite pendente por e-mail no mesmo tenant (substitui o anterior)
            Invitation::query()
                ->where('tenant_id', $tenant->id)
                ->where('email', strtolower($request->input('email')))
                ->whereNull('accepted_at')
                ->delete();

            return Invitation::create([
                'tenant_id' => $tenant->id,
                'email' => strtolower($request->input('email')),
                'role_id' => $role->id,
                'invited_by' => $request->user()->id,
                'token_hash' => $token['hash'],
                'expires_at' => InvitationToken::expiresAt(),
            ]);
        });

        return response()->json([
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => ['id' => $role->id, 'key' => $role->key, 'name' => $role->name],
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'accepted_at' => null,
            ],
            // sem canal de e-mail configurado, o token na resposta é o que
            // permite ao convidado concluir o aceite
            'token' => $token['plain'],
            'accept_path' => '/invitations/'.$token['plain'].'/accept',
        ], 201);
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
    public function accept(Request $request, string $token): JsonResponse
    {
        $user = $request->user();
        $invitation = Invitation::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->where('token_hash', InvitationToken::hash($token))
            ->with(['tenant', 'role'])
            ->first();

        if (! $invitation) {
            return $this->error($request, 404, 'not_found', 'Convite inválido.');
        }

        if ($invitation->isAccepted()) {
            return $this->error($request, 409, 'invitation_already_used', 'Convite já utilizado.');
        }

        if ($invitation->isExpired()) {
            return $this->error($request, 410, 'invitation_expired', 'Convite expirado.');
        }

        if (! $user instanceof User || strcasecmp($user->email, $invitation->email) !== 0) {
            return $this->error($request, 403, 'invitation_email_mismatch', 'O convite pertence a outro e-mail.');
        }

        DB::transaction(function () use ($user, $invitation) {
            Membership::query()
                ->withoutGlobalScope(TenantGlobalScope::class)
                ->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'tenant_id' => $invitation->tenant_id,
                    ],
                    [
                        'role_id' => $invitation->role_id,
                        'status' => Membership::STATUS_ACTIVE,
                    ],
                );

            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        return response()->json(array_merge(
            $this->tokens->tokenPair($user, $invitation->tenant_id),
            [
                'tenant' => [
                    'id' => $invitation->tenant->id,
                    'name' => $invitation->tenant->name,
                    'slug' => $invitation->tenant->slug,
                    'role' => $invitation->role->key,
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'tenants' => $this->userPresenter->tenants($user),
            ],
        ));
    }
}
