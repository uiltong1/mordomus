<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Http\Requests\StoreTenantRequest;
use Mordomus\Identity\Http\Requests\UpdatePreferencesRequest;
use Mordomus\Identity\Http\Requests\UpdateTenantRequest;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\TenantPreference;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\JwtIssuer;
use Mordomus\Identity\Services\RefreshTokenService;

/**
 * T1.2.4 (CRUD de residências) e T1.2.7 (preferências do tenant).
 */
class TenantController extends Controller
{
    /** GET /tenants — residências do usuário (paginação offset, ADR-006). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);

        // listagem de residências do usuário: cross-tenant por definição
        $memberships = Membership::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->with(['tenant', 'role'])
            ->where('user_id', $user->id)
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $memberships->getCollection()
            ->map(fn (Membership $membership) => $this->tenantPayload($membership->tenant, $membership))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $memberships->total(),
                'last_page' => $memberships->lastPage(),
            ],
        ]);
    }

    /** GET /tenants/{tenant} */
    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);
        $membership = $this->membershipOf($request->user(), $tenant);

        if (! $membership) {
            return $this->error($request, 403, 'membership_required', 'Sem acesso à residência.');
        }

        return response()->json(['data' => $this->tenantPayload($tenant, $membership)]);
    }

    /** POST /tenants — cria residência e membership `owner` (T1.2.4). */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tenant = DB::transaction(function () use ($request, $user) {
            $tenant = Tenant::create([
                'name' => $request->input('name'),
                'timezone' => $request->input('timezone') ?: 'America/Sao_Paulo',
                'preferred_hour' => $request->input('preferred_hour') ?: '09:00',
            ]);

            $tenant->preferences()->create([
                'quiet_hours' => TenantPreference::DEFAULT_QUIET_HOURS,
                'channels' => TenantPreference::DEFAULT_CHANNELS,
            ]);

            $membership = Membership::create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'role_id' => Role::systemByKey(Role::OWNER)->id,
                'status' => Membership::STATUS_ACTIVE,
            ]);

            return [$tenant, $membership];
        });

        [$tenant, $membership] = $tenant;

        return response()->json([
            'data' => $this->tenantPayload($tenant, $membership),
            'active_tenant' => $tenant->id,
        ] + $this->reissue($user, $tenant->id), 201);
    }

    /** PATCH /tenants/{tenant} — renomeia, ajusta fuso/horário ou arquiva. */
    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);

        abort_unless($request->user()->can('tenant.manage'), 403, 'forbidden');

        if ($request->boolean('archived')) {
            $tenant->archive();

            return response()->json([
                'data' => $this->tenantPayload($tenant, null),
                'archived' => true,
            ]);
        }

        $tenant->fill($request->only(['name', 'timezone', 'preferred_hour']));
        $tenant->save();

        $membership = $this->membershipOf($request->user(), $tenant);

        return response()->json(['data' => $this->tenantPayload($tenant, $membership)]);
    }

    /** GET /tenants/{tenant}/preferences — T1.2.7. */
    public function preferences(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);
        $membership = $this->membershipOf($request->user(), $tenant);

        if (! $membership) {
            return $this->error($request, 403, 'membership_required', 'Sem acesso à residência.');
        }

        return response()->json(['data' => $this->preferencesPayload($tenant)]);
    }

    /** PATCH /tenants/{tenant}/preferences — preferred_hour + quiet hours + canais. */
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

        return response()->json(['data' => $this->preferencesPayload($tenant)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reissue(User $user, string $tenantId): array
    {
        $tokens = app(JwtIssuer::class)->issue($user, $tenantId);
        $refresh = app(RefreshTokenService::class)->issue($user);

        return array_merge($tokens, [
            'refresh_token' => $refresh['plain'],
            'refresh_expires_in' => $refresh['expires_in'],
        ]);
    }

    private function membershipOf(User $user, Tenant $tenant): ?Membership
    {
        return $user->memberships()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->where('status', Membership::STATUS_ACTIVE)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantPayload(Tenant $tenant, ?Membership $membership): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => $tenant->timezone,
            'preferred_hour' => $tenant->preferred_hour,
            'archived' => $tenant->isArchived(),
            'archived_at' => $tenant->archived_at?->toIso8601String(),
            'role' => $membership?->role ? [
                'id' => $membership->role->id,
                'key' => $membership->role->key,
                'name' => $membership->role->name,
            ] : null,
            'capabilities' => $membership ? $this->capabilitiesOf($membership) : [],
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preferencesPayload(Tenant $tenant): array
    {
        // relação já filtra por tenant_id; o escopo global ficaria `1 = 0` no
        // POST /tenants, que roda sem middleware `tenant`
        $preferences = $tenant->preferences()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->first();

        return [
            'tenant_id' => $tenant->id,
            'preferred_hour' => $tenant->preferred_hour,
            'quiet_hours' => $preferences?->quiet_hours ?? TenantPreference::DEFAULT_QUIET_HOURS,
            'channels' => $preferences?->channels ?? TenantPreference::DEFAULT_CHANNELS,
        ];
    }
}
