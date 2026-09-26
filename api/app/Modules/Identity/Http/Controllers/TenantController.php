<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Identity\Http\Presenters\TenantPresenter;
use Mordomus\Identity\Http\Requests\StoreTenantRequest;
use Mordomus\Identity\Http\Requests\UpdatePreferencesRequest;
use Mordomus\Identity\Http\Requests\UpdateTenantRequest;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\TenantPreference;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\TenantProvisioner;
use Mordomus\Identity\Services\TokenPackager;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\PageMeta;
use Mordomus\OpenApi\Schemas\Preferences;
use Mordomus\OpenApi\Schemas\Session;
use Mordomus\OpenApi\Schemas\Tenant as TenantSchema;
use OpenApi\Attributes as OA;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantPresenter $presenter,
        private readonly TokenPackager $tokens,
        private readonly TenantProvisioner $provisioner,
    ) {}

    #[OA\Get(
        path: '/api/v1/identity/tenants',
        summary: 'Lista as residências do usuário autenticado',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(parameter: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Residências paginadas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: TenantSchema::class)),
                new OA\Property(property: 'meta', ref: PageMeta::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $pagination = OffsetPagination::from($request);

        // a lista de residências do usuário é cross-tenant por definição
        $memberships = Membership::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->with(['tenant', 'role'])
            ->where('user_id', $user->id)
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);

        return response()->json([
            'data' => $memberships->getCollection()
                ->map(fn (Membership $membership): array => $this->presenter->make($membership->tenant, $membership))
                ->values(),
            'meta' => $pagination->meta($memberships->total(), $memberships->lastPage()),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/identity/tenants/{tenant}',
        summary: 'Detalhe da residência com as capabilities do membro',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'tenant', description: 'Id ULID da residência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Residência', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: TenantSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Residência diferente da ativa no token ou sem membership', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Residência não encontrada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);
        $membership = $this->membershipOf($request->user(), $tenant);

        if (! $membership) {
            return $this->error($request, 403, 'membership_required', 'Sem acesso à residência.');
        }

        return response()->json(['data' => $this->presenter->make($tenant, $membership)]);
    }

    #[OA\Post(
        path: '/api/v1/identity/tenants',
        summary: 'Cria residência e devolve sessão já apontando para ela',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Casa Segunda'),
            new OA\Property(property: 'timezone', type: 'string', maxLength: 64, example: 'America/Sao_Paulo'),
            new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00'),
        ], required: ['name'])),
        responses: [
            new OA\Response(response: 201, description: 'Residência criada com o usuário como owner', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: Session::class),
                new OA\Schema(properties: [
                    new OA\Property(property: 'data', ref: TenantSchema::class),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreTenantRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $provisioned = $this->provisioner->create($user, [
            'name' => $request->input('name'),
            'timezone' => $request->input('timezone'),
            'preferred_hour' => $request->input('preferred_hour'),
        ]);

        $tenant = $provisioned['tenant'];

        return response()->json([
            'data' => $this->presenter->make($tenant, $provisioned['membership']),
            'active_tenant' => $tenant->id,
        ] + $this->tokens->session($user, $tenant->id), 201);
    }

    #[OA\Patch(
        path: '/api/v1/identity/tenants/{tenant}',
        summary: 'Renomeia, ajusta fuso/horário ou arquiva a residência',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'tenant', description: 'Id ULID da residência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 120),
            new OA\Property(property: 'timezone', type: 'string', maxLength: 64),
            new OA\Property(property: 'preferred_hour', type: 'string', example: '09:00'),
            new OA\Property(property: 'archived', type: 'boolean', description: 'true arquiva; false restaura'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Residência atualizada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: TenantSchema::class),
                new OA\Property(property: 'archived', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Residência diferente da ativa ou capability tenant.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Residência não encontrada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);

        abort_unless($request->user()->can('tenant.manage'), 403, 'forbidden');

        if ($request->boolean('archived')) {
            $tenant->archive();

            return response()->json([
                'data' => $this->presenter->make($tenant),
                'archived' => true,
            ]);
        }

        $tenant->fill($request->only(['name', 'timezone', 'preferred_hour']));
        $tenant->save();

        return response()->json([
            'data' => $this->presenter->make($tenant, $this->membershipOf($request->user(), $tenant)),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/identity/tenants/{tenant}/preferences',
        summary: 'Lê as preferências da residência',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'tenant', description: 'Id ULID da residência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Preferências', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: Preferences::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Residência diferente da ativa ou sem membership', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Residência não encontrada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function preferences(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);
        $membership = $this->membershipOf($request->user(), $tenant);

        if (! $membership) {
            return $this->error($request, 403, 'membership_required', 'Sem acesso à residência.');
        }

        return response()->json(['data' => $this->presenter->preferences($tenant)]);
    }

    #[OA\Patch(
        path: '/api/v1/identity/tenants/{tenant}/preferences',
        summary: 'Atualiza horário preferido, quiet hours e canais',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'tenant', description: 'Id ULID da residência', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'preferred_hour', type: 'string', example: '21:30'),
            new OA\Property(property: 'quiet_hours', type: 'object', properties: [
                new OA\Property(property: 'start', type: 'string', example: '22:00'),
                new OA\Property(property: 'end', type: 'string', example: '07:00'),
            ]),
            new OA\Property(property: 'channels', type: 'object', properties: [
                new OA\Property(property: 'email', type: 'boolean'),
                new OA\Property(property: 'push', type: 'boolean'),
                new OA\Property(property: 'in_app', type: 'boolean'),
            ]),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Preferências atualizadas', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: Preferences::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Residência diferente da ativa ou capability tenant.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Residência não encontrada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function updatePreferences(UpdatePreferencesRequest $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);

        abort_unless($request->user()->can('tenant.manage'), 403, 'forbidden');

        if ($request->has('preferred_hour')) {
            $tenant->preferred_hour = $request->input('preferred_hour');
            $tenant->save();
        }

        $preferences = $tenant->preferences ?: $tenant->preferences()->make([
            'quiet_hours' => TenantPreference::DEFAULT_QUIET_HOURS,
            'channels' => TenantPreference::DEFAULT_CHANNELS,
        ]);

        $preferences->fill($request->only(['quiet_hours', 'channels']));
        $preferences->save();

        return response()->json(['data' => $this->presenter->preferences($tenant)]);
    }

    private function membershipOf(User $user, Tenant $tenant): ?Membership
    {
        return $user->memberships()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->where('status', Membership::STATUS_ACTIVE)
            ->first();
    }
}
