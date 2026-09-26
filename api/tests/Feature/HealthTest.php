<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_root_returns_service_info(): void
    {
        $this->get('/')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_health_returns_ok(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', config('app.name'));
    }

    public function test_scoped_health_returns_ok(): void
    {
        $this->get('/api/v1/identity/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_health_ignores_forged_tenant_header(): void
    {
        $this->withHeaders(['X-Tenant-ID' => '01arzunyk9vesss7y1deedthu'])
            ->getJson('/health')
            ->assertOk()
            ->assertJsonPath('tenant', null);
    }
}
