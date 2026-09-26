<?php

namespace Tests\Feature;

use Mordomus\Identity\Models\Tenant;

/**
 * T1.2.4 — CRUD de residências (tenants): lista paginada, criação, edição e arquivamento.
 */
class TenantCrudTest extends FeatureTestCase
{
    public function test_tenant_can_be_created_renamed_and_listed(): void
    {
        $auth = $this->registerUser('casas@mordomus.test', 'Casas', 'Casa Um');
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $created = $this->withHeaders($headers)->postJson('/api/v1/identity/tenants', ['name' => 'Casa Dois']);
        $created->assertCreated()->assertJsonPath('data.name', 'Casa Dois');
        $secondId = $created->json('data.id');

        // editar exige a residência ativa no token (tid) → trocar antes
        $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$secondId}", ['name' => 'Chácara'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenant_mismatch');

        $switched = $this->withHeaders($headers)
            ->postJson('/api/v1/identity/auth/switch-tenant', ['tenant_id' => $secondId]);
        $switched->assertOk();
        $headers['Authorization'] = 'Bearer '.$switched->json('access_token');

        $renamed = $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$secondId}", ['name' => 'Chácara']);
        $renamed->assertOk()->assertJsonPath('data.name', 'Chácara');

        $list = $this->withHeaders($headers)->getJson('/api/v1/identity/tenants?page=1&per_page=1');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame(1, $list->json('meta.per_page'));
        $this->assertSame(2, $list->json('meta.last_page'));

        $show = $this->withHeaders($headers)->getJson("/api/v1/identity/tenants/{$secondId}");
        $show->assertOk()->assertJsonPath('data.id', $secondId);
    }

    public function test_empty_name_is_rejected(): void
    {
        $auth = $this->registerUser('vazio@mordomus.test');
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$auth['active_tenant']}", ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_archiving_removes_tenant_from_list_and_blocks_access(): void
    {
        $auth = $this->registerUser('arquiva@mordomus.test');
        $tenantId = $auth['active_tenant'];
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $archived = $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$tenantId}", ['archived' => true]);
        $archived->assertOk()->assertJsonPath('data.archived', true);

        $this->withHeaders($headers)
            ->getJson('/api/v1/identity/tenants')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // memberships são arquivados junto → middleware tenant bloqueia
        $this->withHeaders($headers)
            ->getJson("/api/v1/identity/tenants/{$tenantId}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'membership_required');

        $this->assertTrue(Tenant::query()->find($tenantId)->archived_at !== null);
    }
}
