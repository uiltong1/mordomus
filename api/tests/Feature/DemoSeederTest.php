<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * O cenário de demonstração é contrato de ambiente: o README promete duas casas
 * com cômodo, inventário, agenda e conta, e é isso que o painel abre na
 * primeira visita. Seed que roda e não monta nada é falha silenciosa.
 */
class DemoSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemo();
    }

    public function test_the_scenario_lands_two_houses_with_the_advertised_inventory(): void
    {
        $this->assertSame(2, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertSame(5, User::query()->withoutGlobalScopes()->count());

        $this->assertSame(3, DB::table('rooms')->count());
        $this->assertSame(5, DB::table('assets')->count());
        $this->assertSame(6, DB::table('bills')->count());

        // Regras de manutenção têm alvo `asset`; a cadência de cada conta é
        // escrita pelo módulo dono quando a conta nasce.
        $this->assertSame(4, $this->rulesFor('asset'));
        $this->assertSame(6, $this->rulesFor('bill'));
    }

    public function test_the_scenario_materializes_something_to_do(): void
    {
        $this->assertGreaterThan(0, JobSchedule::query()->withoutGlobalScopes()->count());
    }

    public function test_applying_the_scenario_twice_does_not_duplicate(): void
    {
        $this->seedDemo();

        $this->assertSame(2, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertSame(3, DB::table('rooms')->count());
        $this->assertSame(5, DB::table('assets')->count());
        $this->assertSame(6, DB::table('bills')->count());
        $this->assertSame(4, $this->rulesFor('asset'));
        $this->assertSame(6, $this->rulesFor('bill'));
    }

    public function test_the_owner_logs_in_and_reads_the_house_through_the_api(): void
    {
        $tenantId = Tenant::query()->withoutGlobalScopes()->where('name', 'Residência Vila Madalena')->value('id');
        $owner = User::query()->withoutGlobalScopes()->where('email', 'ana@mordomus.test')->firstOrFail();

        $login = $this->postJson('/api/v1/identity/auth/login', [
            'email' => $owner->email,
            'password' => 'senha-demo-123',
        ])->assertOk();

        $this->assertSame($tenantId, $login->json('active_tenant'));

        $this->withHeaders($this->authHeadersFor($owner, (string) $tenantId))
            ->getJson('/api/v1/maintenance/rooms')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    private function seedDemo(): void
    {
        Artisan::call('db:seed', ['--demo' => true, '--force' => true]);
    }

    private function rulesFor(string $subjectType): int
    {
        return TriggerConfig::query()
            ->withoutGlobalScopes()
            ->where('subject_type', $subjectType)
            ->count();
    }
}
