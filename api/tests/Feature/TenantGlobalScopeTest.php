<?php

declare(strict_types=1);

namespace Tests\Feature;

use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;

/**
 * `BelongsToTenant` / `TenantGlobalScope` nos models do módulo.
 *
 * Fail-closed: sem contexto de residência nenhuma linha passa; com contexto,
 * só as daquela residência.
 */
class TenantGlobalScopeTest extends FeatureTestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $user = User::factory()->create();
        $this->seedMembership($user, $this->tenantA);
        $this->seedMembership($user, $this->tenantB);
    }

    public function test_query_without_context_is_fail_closed(): void
    {
        $this->assertSame([], Membership::query()->pluck('tenant_id')->all());
    }

    public function test_query_with_context_only_returns_that_tenants_rows(): void
    {
        TenantContext::set($this->tenantA->id);

        try {
            $tenantIds = Membership::query()->pluck('tenant_id')->unique()->all();
        } finally {
            TenantContext::clear();
        }

        $this->assertSame([$this->tenantA->id], $tenantIds);
    }

    public function test_context_is_restored_after_run_with(): void
    {
        $this->assertFalse(TenantContext::has());

        $inside = TenantContext::runWith($this->tenantA->id, fn (): bool => TenantContext::has());

        $this->assertTrue($inside);
        $this->assertFalse(TenantContext::has());
    }

    public function test_creating_autofills_tenant_id_from_context(): void
    {
        $user = User::factory()->create();
        $role = Role::systemByKey(Role::MEMBER);

        $membership = TenantContext::runWith($this->tenantA->id, fn (): Membership => Membership::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => Membership::STATUS_ACTIVE,
        ]));

        $this->assertSame($this->tenantA->id, $membership->tenant_id);
    }

    public function test_creating_without_tenant_id_and_context_is_rejected(): void
    {
        $user = User::factory()->create();
        $role = Role::systemByKey(Role::MEMBER);

        $this->expectException(\RuntimeException::class);

        Membership::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);
    }

    public function test_scope_can_be_lifted_explicitly(): void
    {
        $all = Membership::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->pluck('tenant_id')
            ->unique()
            ->all();

        $this->assertCount(2, $all);
    }

    private function seedMembership(User $user, Tenant $tenant): void
    {
        Membership::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::systemByKey(Role::OWNER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);
    }
}
