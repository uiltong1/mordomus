<?php

namespace Tests\Feature;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mordomus\Common\Support\TenantContext;
use Tests\TestCase;

abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /** Tenant criado junto com a rota de registro. */
    protected function registerUser(
        string $email,
        string $name = 'Usuário Teste',
        string $home = 'Casa Teste',
    ): array {
        $response = $this->postJson('/api/v1/identity/auth/register', [
            'name' => $name,
            'email' => $email,
            'password' => 'senha-forte-1',
            'password_confirmation' => 'senha-forte-1',
            'home_name' => $home,
        ]);

        $response->assertCreated();

        return $response->json();
    }

    /**
     * Leitura direta de um model com TenantGlobalScope.
     *
     * Em requisição HTTP o middleware `tenant` popula o TenantContext; aqui
     * fora (teste/CLI) a residência precisa ser dada explicitamente — é o
     * fail-closed do escopo global.
     */
    protected function withTenantContext(string $tenantId, callable $callback): mixed
    {
        return TenantContext::runWith($tenantId, $callback);
    }
}
