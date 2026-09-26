<?php

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Permission;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\CapabilityResolver;

/**
 * T1.2.8/T1.2.10 — resolução Can() (ADR-007) e member sem rules.edit → 403.
 */
class RbacTest extends FeatureTestCase
{
    private User $owner;

    private User $member;

    private Tenant $tenant;

    private Membership $ownerMembership;

    private Membership $memberMembership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->ownerMembership = Membership::create([
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::systemByKey(Role::OWNER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $this->memberMembership = Membership::create([
            'user_id' => $this->member->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        app()->instance('mordomus.tenant_id', $this->tenant->id);
    }

    public function test_owner_has_every_capability_and_member_has_policy_default(): void
    {
        foreach (array_keys(\Database\Seeders\RbacSeeder::CAPABILITIES) as $capability) {
            $this->assertTrue($this->owner->hasCapability($capability), "owner deveria ter {$capability}");
        }

        $this->assertTrue($this->member->hasCapability('occurrences.complete'));
        $this->assertTrue($this->member->hasCapability('bills.pay'));
        $this->assertTrue($this->member->hasCapability('splits.view_own'));
        $this->assertTrue($this->member->hasCapability('notifications.manage'));
        $this->assertTrue($this->member->hasCapability('occurrences.skip'));

        $this->assertFalse($this->member->hasCapability('rules.edit'));
        $this->assertFalse($this->member->hasCapability('members.manage'));
        $this->assertFalse($this->member->hasCapability('tenant.manage'));
        $this->assertFalse($this->member->hasCapability('bills.manage'));
    }

    public function test_membership_grants_override_role_permissions(): void
    {
        $permission = Permission::query()->where('key', 'rules.edit')->firstOrFail();
        $resolver = app(CapabilityResolver::class);

        $this->assertFalse($this->member->hasCapability('rules.edit'));

        $this->memberMembership->permissionGrants()->attach($permission->id, ['granted' => true]);
        $resolver->forget($this->memberMembership);

        $this->assertTrue($this->member->hasCapability('rules.edit'));

        $this->memberMembership->permissionGrants()->updateExistingPivot($permission->id, ['granted' => false]);
        $resolver->forget($this->memberMembership);

        $this->assertFalse($this->member->hasCapability('rules.edit'));
    }

    public function test_member_without_rules_edit_gets_403_from_gate(): void
    {
        $this->assertTrue(
            Gate::forUser($this->owner)->allows('rules.edit'),
            'owner deveria poder criar/editar regras',
        );

        $this->assertFalse(
            Gate::forUser($this->member)->allows('rules.edit'),
            'member sem rules.edit deveria ser recusado',
        );

        $this->expectException(AuthorizationException::class);

        Gate::forUser($this->member)->authorize('rules.edit');
    }

    public function test_member_gets_403_on_members_manage_route(): void
    {
        $headers = $this->authHeadersFor($this->member, $this->tenant->id);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$this->tenant->id}/members/{$this->ownerMembership->id}", [
                'role_id' => Role::systemByKey(Role::MEMBER)->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_member_gets_403_on_tenant_manage_route(): void
    {
        $headers = $this->authHeadersFor($this->member, $this->tenant->id);
        $originalName = $this->tenant->name;

        $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$this->tenant->id}", ['name' => 'Hacked'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertSame($originalName, Tenant::query()->find($this->tenant->id)->name);
    }

    public function test_cross_tenant_access_is_forbidden(): void
    {
        $otherTenant = Tenant::factory()->create();

        $headers = $this->authHeadersFor($this->owner, $this->tenant->id);

        $this->withHeaders($headers)
            ->getJson("/api/v1/identity/tenants/{$otherTenant->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenant_mismatch');
    }

    public function test_capability_resolution_requires_active_membership(): void
    {
        $this->ownerMembership->update(['status' => Membership::STATUS_ARCHIVED]);
        app(CapabilityResolver::class)->forget($this->ownerMembership);

        $this->assertFalse($this->owner->hasCapability('rules.edit'));
    }
}
