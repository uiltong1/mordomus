<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RbacSeeder;
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
}
