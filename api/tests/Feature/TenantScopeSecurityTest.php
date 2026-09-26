<?php

declare(strict_types=1);

namespace Tests\Feature;

use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;

/**
 * Regressão de segurança do escopo por residência.
 *
 * - header `X-Tenant-ID` forjado é descartado pelo gateway e nunca lido pelo backend;
 * - sem token / token adulterado → 401;
 * - membership não ativo → 403;
 * - usuário do Tenant A não lê nem grava dados do Tenant B.
 */
class TenantScopeSecurityTest extends FeatureTestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
        $this->owner = User::factory()->create();

        Membership::create([
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenantA->id,
            'role_id' => Role::systemByKey(Role::OWNER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);
    }

    public function test_forged_tenant_header_is_ignored(): void
    {
        $this->withHeaders([
            'X-Tenant-ID' => $this->tenantB->id,
        ] + $this->authHeadersFor($this->owner, $this->tenantA->id))
            ->getJson("/api/v1/identity/tenants/{$this->tenantA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->tenantA->id);
    }

    public function test_forged_tenant_header_does_not_authenticate(): void
    {
        $this->withHeaders(['X-Tenant-ID' => $this->tenantA->id])
            ->getJson("/api/v1/identity/tenants/{$this->tenantA->id}")
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_tampered_token_is_rejected(): void
    {
        $headers = $this->authHeadersFor($this->owner, $this->tenantA->id);
        $token = substr((string) $headers['Authorization'], 7);
        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);
        $headers['Authorization'] = 'Bearer '.implode('.', $parts);

        $this->withHeaders($headers)
            ->getJson("/api/v1/identity/tenants/{$this->tenantA->id}")
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_archived_membership_is_denied(): void
    {
        $headers = $this->authHeadersFor($this->owner, $this->tenantA->id);

        $this->withTenantContext($this->tenantA->id, fn () => Membership::query()
            ->where('user_id', $this->owner->id)
            ->where('tenant_id', $this->tenantA->id)
            ->update(['status' => Membership::STATUS_ARCHIVED]));

        $this->withHeaders($headers)
            ->getJson("/api/v1/identity/tenants/{$this->tenantA->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'membership_required');
    }

    public function test_missing_tenant_claim_is_denied(): void
    {
        $this->withHeaders($this->authHeadersFor($this->owner, null))
            ->getJson("/api/v1/identity/tenants/{$this->tenantA->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_required');
    }

    public function test_tenant_b_data_is_unreachable(): void
    {
        $headers = $this->authHeadersFor($this->owner, $this->tenantA->id);

        $this->withHeaders($headers)
            ->getJson("/api/v1/identity/tenants/{$this->tenantB->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_mismatch');

        $this->withHeaders($headers)
            ->getJson("/api/v1/identity/tenants/{$this->tenantB->id}/members")
            ->assertForbidden();
    }

    public function test_tenant_b_data_cannot_be_written(): void
    {
        $this->withHeaders($this->authHeadersFor($this->owner, $this->tenantA->id))
            ->patchJson("/api/v1/identity/tenants/{$this->tenantB->id}", ['name' => 'Hacked'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_mismatch');

        $this->assertSame($this->tenantB->name, Tenant::query()->find($this->tenantB->id)->name);
    }
}
