<?php

declare(strict_types=1);

namespace Mordomus\Identity\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mordomus\Identity\Contracts\Repositories\InvitationRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\RoleRepositoryInterface;
use Mordomus\Identity\Contracts\Services\InvitationServiceInterface;
use Mordomus\Identity\Contracts\Services\TokenPackagerServiceInterface;
use Mordomus\Identity\Exceptions\InvitationAlreadyUsed;
use Mordomus\Identity\Exceptions\InvitationEmailMismatch;
use Mordomus\Identity\Exceptions\InvitationExpired;
use Mordomus\Identity\Exceptions\InvitationNotFound;
use Mordomus\Identity\Http\Resources\UserResource;
use Mordomus\Identity\Models\Invitation;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;

final class InvitationService implements InvitationServiceInterface
{
    public function __construct(
        private readonly InvitationRepositoryInterface $invitations,
        private readonly MembershipRepositoryInterface $memberships,
        private readonly RoleRepositoryInterface $roles,
        private readonly TokenPackagerServiceInterface $tokens,
        private readonly UserResource $resource,
    ) {}

    public function store(Request $request, Tenant $tenant): array
    {
        $role = $request->filled('role_id')
            ? $this->roles->findOrFail($request->input('role_id'))
            : $this->roles->systemByKey(Role::MEMBER);

        $token = InvitationTokenService::generate();
        $email = strtolower($request->input('email'));

        $invitation = DB::transaction(function () use ($tenant, $email, $request, $role, $token): Invitation {
            // um convite pendente por e-mail no mesmo tenant (substitui o anterior)
            $this->invitations->replacePending($tenant->id, $email);

            return $this->invitations->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'role_id' => $role->id,
                'invited_by' => $request->user()->id,
                'token_hash' => $token['hash'],
                'expires_at' => InvitationTokenService::expiresAt(),
            ]);
        });

        return [
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
        ];
    }

    public function accept(Request $request, string $token): array
    {
        $user = $request->user();
        $invitation = $this->invitations->findByTokenHash(InvitationTokenService::hash($token));

        if (! $invitation) {
            throw InvitationNotFound::make();
        }

        if ($invitation->isAccepted()) {
            throw InvitationAlreadyUsed::make();
        }

        if ($invitation->isExpired()) {
            throw InvitationExpired::make();
        }

        if (! $user instanceof User || strcasecmp($user->email, $invitation->email) !== 0) {
            throw InvitationEmailMismatch::make();
        }

        DB::transaction(function () use ($user, $invitation): void {
            $this->memberships->activateForInvitation(
                $user,
                $invitation->tenant_id,
                $invitation->role_id,
            );

            $this->invitations->markAccepted($invitation);
        });

        return array_merge(
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
                'tenants' => $this->resource->tenants($user),
            ],
        );
    }
}
