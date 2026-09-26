<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Http\Requests\StoreInvitationRequest;
use Mordomus\Identity\Models\Invitation;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\InvitationToken;
use Mordomus\Identity\Services\JwtIssuer;
use Mordomus\Identity\Services\RefreshTokenService;

/**
 * T1.2.6 — convites: token hasheado, expiração em 7 dias, aceite cria Membership.
 */
class InvitationController extends Controller
{
    /** POST /tenants/{tenant}/invitations — exige capability `members.manage`. */
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
            // dev/prod: em T6.1 o token sai por e-mail (Mailpit em dev — ADR-010)
            'token' => $token['plain'],
            'accept_path' => '/invitations/'.$token['plain'].'/accept',
        ], 201);
    }

    /** POST /invitations/{token}/accept */
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

        $issuer = app(JwtIssuer::class);
        $refresh = app(RefreshTokenService::class);

        return response()->json(array_merge(
            $issuer->issue($user, $invitation->tenant_id),
            [
                'refresh_token' => $refresh->issue($user)['plain'],
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
                'tenants' => $user->tenantSummaries(),
            ],
        ));
    }
}
