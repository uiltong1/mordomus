<?php

namespace Tests\Feature;

use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;

/**
 * Preferências do tenant: preferred_hour (09:00) + quiet hours.
 */
class TenantPreferencesTest extends FeatureTestCase
{
    public function test_defaults_are_created_with_the_tenant(): void
    {
        $auth = $this->registerUser('pref@mordomus.test', 'Pref', 'Casa Pref');
        $tenantId = $auth['active_tenant'];
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/identity/tenants/{$tenantId}/preferences");

        $response->assertOk()->assertJsonPath('data.preferred_hour', '09:00');
        $this->assertSame(['start' => '22:00', 'end' => '07:00'], $response->json('data.quiet_hours'));
        $this->assertTrue($response->json('data.channels.email'));
        $this->assertTrue($response->json('data.channels.push'));
        $this->assertTrue($response->json('data.channels.in_app'));
    }

    public function test_owner_updates_quiet_hours_and_channels(): void
    {
        $auth = $this->registerUser('quiet@mordomus.test', 'Quiet', 'Casa Quiet');
        $tenantId = $auth['active_tenant'];
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $updated = $this->withHeaders($headers)->patchJson(
            "/api/v1/identity/tenants/{$tenantId}/preferences",
            [
                'preferred_hour' => '08:30',
                'quiet_hours' => ['start' => '21:30', 'end' => '06:45'],
                'channels' => ['email' => false, 'push' => true, 'in_app' => true],
            ],
        );

        $updated->assertOk()->assertJsonPath('data.preferred_hour', '08:30');
        $this->assertSame(['start' => '21:30', 'end' => '06:45'], $updated->json('data.quiet_hours'));
        $this->assertFalse($updated->json('data.channels.email'));

        // persistido no tenant
        $this->withHeaders($headers)
            ->getJson("/api/v1/identity/tenants/{$tenantId}/preferences")
            ->assertJsonPath('data.preferred_hour', '08:30');
    }

    public function test_member_cannot_update_preferences(): void
    {
        $auth = $this->registerUser('dono-pref@mordomus.test', 'Dono', 'Casa Dono');
        $tenantId = $auth['active_tenant'];

        $member = User::factory()->create(['email' => 'morador-pref@mordomus.test']);
        Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        // member tem notifications.manage → pode ler
        $this->asUser($member, $tenantId)
            ->getJson("/api/v1/identity/tenants/{$tenantId}/preferences")
            ->assertOk();

        // mas não tenant.manage → não grava
        $this->asUser($member, $tenantId)
            ->patchJson("/api/v1/identity/tenants/{$tenantId}/preferences", ['preferred_hour' => '03:00'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_invalid_hour_format_is_rejected(): void
    {
        $auth = $this->registerUser('valida@mordomus.test');
        $tenantId = $auth['active_tenant'];
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $this->withHeaders($headers)
            ->patchJson("/api/v1/identity/tenants/{$tenantId}/preferences", [
                'preferred_hour' => '9h30',
                'quiet_hours' => ['start' => 'noite', 'end' => '07:00'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }
}
