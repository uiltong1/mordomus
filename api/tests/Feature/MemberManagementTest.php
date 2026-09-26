<?php

namespace Tests\Feature;

use Mordomus\Identity\Models\Invitation;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\CapabilityResolverService;
use Mordomus\Identity\Services\InvitationTokenService;

/**
 * Convites e gestão de membros (role e grants).
 */
class MemberManagementTest extends FeatureTestCase
{
    public function test_owner_invites_and_invitee_accepts(): void
    {
        $auth = $this->registerUser('dono@mordomus.test', 'Dono', 'Casa Um');
        $tenantId = $auth['active_tenant'];
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $invite = $this->withHeaders($headers)->postJson(
            "/api/v1/identity/tenants/{$tenantId}/invitations",
            ['email' => 'moradora@mordomus.test'],
        );

        $invite->assertCreated()->assertJsonPath('data.email', 'moradora@mordomus.test');
        $token = $invite->json('token');
        $this->assertNotEmpty($token);

        // apenas o hash persiste
        $invitation = $this->withTenantContext($tenantId, fn () => Invitation::query()
            ->where('tenant_id', $tenantId)
            ->firstOrFail());
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());

        // convite pertence a outro e-mail → 403
        $intruso = User::factory()->create(['email' => 'intruso@mordomus.test']);
        $this->asUser($intruso)
            ->postJson("/api/v1/identity/invitations/{$token}/accept")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'invitation_email_mismatch');

        // aceite cria membership ativo com role member e troca o tenant ativo
        $invitee = User::factory()->create(['email' => 'moradora@mordomus.test']);
        $accept = $this->asUser($invitee)->postJson("/api/v1/identity/invitations/{$token}/accept");

        $accept->assertOk()->assertJsonPath('active_tenant', $tenantId);
        $this->assertCount(1, $accept->json('tenants'));

        $membership = $this->withTenantContext($tenantId, fn () => Membership::query()
            ->where('user_id', $invitee->id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail());

        $this->assertSame(Membership::STATUS_ACTIVE, $membership->status);
        $this->assertSame(Role::MEMBER, $membership->role->key);

        // token não reutilizável
        $this->asUser($invitee, $tenantId)
            ->postJson("/api/v1/identity/invitations/{$token}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invitation_already_used');
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $auth = $this->registerUser('velho@mordomus.test');
        $tenantId = $auth['active_tenant'];

        $token = InvitationTokenService::generate();
        Invitation::create([
            'tenant_id' => $tenantId,
            'email' => 'atrasada@mordomus.test',
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'token_hash' => $token['hash'],
            'expires_at' => now()->subDay(),
        ]);

        $invitee = User::factory()->create(['email' => 'atrasada@mordomus.test']);

        $this->asUser($invitee)
            ->postJson("/api/v1/identity/invitations/{$token['plain']}/accept")
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'invitation_expired');
    }

    public function test_unknown_invitation_token_is_404(): void
    {
        $auth = $this->registerUser('quem@mordomus.test');
        $user = User::query()->findOrFail($auth['user']['id']);

        $this->asUser($user)
            ->postJson('/api/v1/identity/invitations/token-inexistente/accept')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_owner_lists_members_and_changes_role(): void
    {
        $auth = $this->registerUser('chefe@mordomus.test', 'Chefe', 'Casa Chefe');
        $tenantId = $auth['active_tenant'];
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $invitee = User::factory()->create(['email' => 'nova@mordomus.test']);
        Membership::create([
            'user_id' => $invitee->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $members = $this->withHeaders($headers)->getJson("/api/v1/identity/tenants/{$tenantId}/members");
        $members->assertOk();
        $this->assertCount(2, $members->json('data'));

        $memberId = collect($members->json('data'))
            ->firstWhere('user.email', 'nova@mordomus.test')['id'];

        // member não pode alterar roles (capability members.manage)
        $memberMembership = $this->withTenantContext($tenantId, fn () => Membership::query()
            ->where('user_id', $invitee->id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail());

        $this->asUser($invitee, $tenantId)
            ->patchJson("/api/v1/identity/tenants/{$tenantId}/members/{$memberMembership->id}", [
                'role_id' => Role::systemByKey(Role::OWNER)->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        // dono troca a role → membro passa a ter todas as capabilities
        $ownerMembership = $this->withTenantContext($tenantId, fn () => Membership::query()
            ->where('user_id', $auth['user']['id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail());

        $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$tenantId}/members/{$memberId}", [
                'role_id' => Role::systemByKey(Role::MEMBER)->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.role.key', 'member');

        // dono não pode alterar a própria role
        $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$tenantId}/members/{$ownerMembership->id}", [
                'role_id' => Role::systemByKey(Role::MEMBER)->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'cannot_change_own_role');
    }

    public function test_owner_grants_capability_to_member(): void
    {
        $auth = $this->registerUser('gerente@mordomus.test', 'Gerente', 'Casa Gerente');
        $tenantId = $auth['active_tenant'];
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];
        app()->instance('mordomus.tenant_id', $tenantId);

        $member = User::factory()->create(['email' => 'funcionario@mordomus.test']);
        $membership = Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $this->assertFalse($member->hasCapability('rules.edit'));

        $grant = $this->withHeaders($headers)->putJson(
            "/api/v1/identity/tenants/{$tenantId}/members/{$membership->id}/grants",
            ['capability' => 'rules.edit', 'granted' => true],
        );

        $grant->assertOk();
        $this->assertContains('rules.edit', $grant->json('data.capabilities'));

        // capability efetiva do membro no tenant
        $me = $this->asUser($member, $tenantId)->getJson('/api/v1/identity/me');
        $me->assertOk();
        $this->assertContains('rules.edit', $me->json('data.capabilities'));

        // revoga
        $this->withHeaders($headers)->putJson(
            "/api/v1/identity/tenants/{$tenantId}/members/{$membership->id}/grants",
            ['capability' => 'rules.edit', 'granted' => false],
        )->assertOk();

        $this->assertNotContains('rules.edit', app(CapabilityResolverService::class)->keys($membership));
    }

    public function test_invitation_requires_members_manage_capability(): void
    {
        $auth = $this->registerUser('limite@mordomus.test');
        $tenantId = $auth['active_tenant'];

        $member = User::factory()->create(['email' => 'sem-permissao@mordomus.test']);
        Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $this->asUser($member, $tenantId)
            ->postJson("/api/v1/identity/tenants/{$tenantId}/invitations", [
                'email' => 'x@mordomus.test',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }
}
