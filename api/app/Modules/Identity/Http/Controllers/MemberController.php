<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Http\Requests\UpdateMemberGrantsRequest;
use Mordomus\Identity\Http\Requests\UpdateMemberRequest;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Permission;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\CapabilityResolver;

/**
 * T1.2.9 — gestão de membros (troca de role + grant/revoke de capability).
 */
class MemberController extends Controller
{
    /** GET /tenants/{tenant}/members — leitura exige só o tenant ativo. */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertTenant($tenant, $request);

        $memberships = Membership::query()
            ->with(['user', 'role'])
            ->where('tenant_id', $tenant->id)
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get();

        $resolver = app(CapabilityResolver::class);

        return response()->json([
            'data' => $memberships->map(fn (Membership $membership) => [
                'id' => $membership->id,
                'user' => [
                    'id' => $membership->user->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                ],
                'role' => [
                    'id' => $membership->role->id,
                    'key' => $membership->role->key,
                    'name' => $membership->role->name,
                ],
                'status' => $membership->status,
                'capabilities' => $resolver->keys($membership),
            ])->values(),
        ]);
    }

    /** PATCH /tenants/{tenant}/members/{membership} — troca de role. */
    public function update(UpdateMemberRequest $request, Tenant $tenant, Membership $membership): JsonResponse
    {
        $this->assertTenant($tenant, $request);

        abort_unless($request->user()->can('members.manage'), 403, 'forbidden');

        if ($membership->tenant_id !== $tenant->id) {
            return $this->error($request, 404, 'not_found', 'Membro não pertence à residência.');
        }

        if ($membership->user_id === $request->user()->id) {
            return $this->error($request, 422, 'cannot_change_own_role', 'Não é possível alterar a própria role.');
        }

        $membership->role_id = $request->input('role_id');
        $membership->save();
        $membership->load(['user', 'role']);
        app(CapabilityResolver::class)->forget($membership);

        return response()->json(['data' => $this->memberPayload($membership)]);
    }

    /** PUT /tenants/{tenant}/members/{membership}/grants — grant/revoke pontual (ADR-007). */
    public function updateGrants(UpdateMemberGrantsRequest $request, Tenant $tenant, Membership $membership): JsonResponse
    {
        $this->assertTenant($tenant, $request);

        abort_unless($request->user()->can('members.manage'), 403, 'forbidden');

        if ($membership->tenant_id !== $tenant->id) {
            return $this->error($request, 404, 'not_found', 'Membro não pertence à residência.');
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

        app(CapabilityResolver::class)->forget($membership);
        $membership->load(['user', 'role']);

        return response()->json(['data' => $this->memberPayload($membership)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPayload(Membership $membership): array
    {
        /** @var User $user */
        $user = $membership->user;

        return [
            'id' => $membership->id,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'role' => [
                'id' => $membership->role->id,
                'key' => $membership->role->key,
                'name' => $membership->role->name,
            ],
            'status' => $membership->status,
            'capabilities' => $this->capabilitiesOf($membership),
        ];
    }
}
