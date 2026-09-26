<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Hash;
use Mordomus\Identity\Models\RefreshToken;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\JwtIssuerService;

/**
 * Registro → login → refresh (rotação) → switch-tenant.
 */
class AuthFlowTest extends FeatureTestCase
{
    public function test_register_creates_user_owner_and_first_tenant(): void
    {
        $payload = $this->registerUser('ana@mordomus.test', 'Ana', 'Casa Principal');

        $this->assertNotEmpty($payload['access_token']);
        $this->assertNotEmpty($payload['refresh_token']);
        $this->assertSame('bearer', $payload['token_type']);
        $this->assertNotNull($payload['active_tenant']);
        $this->assertCount(1, $payload['tenants']);
        $this->assertSame('owner', $payload['tenants'][0]['role_key']);
        $this->assertSame('Casa Principal', $payload['tenants'][0]['name']);
        $this->assertContains('rules.edit', $payload['tenants'][0]['capabilities']);
        $this->assertSame('ana@mordomus.test', $payload['user']['email']);

        $user = User::query()->where('email', 'ana@mordomus.test')->firstOrFail();
        $this->assertTrue(Hash::check('senha-forte-1', $user->password_hash));
        $this->assertSame('argon2id', password_get_info($user->password_hash)['algoName']);

        $claims = $this->claimsOf($payload['access_token']);
        $this->assertSame($user->id, $claims['sub']);
        $this->assertSame($payload['active_tenant'], $claims['tid']);
        $this->assertSame('mordomus', $claims['iss']);
        $this->assertSame([$payload['active_tenant']], $claims['tenants']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->registerUser('dup@mordomus.test');

        $this->postJson('/api/v1/identity/auth/register', [
            'name' => 'Outra',
            'email' => 'dup@mordomus.test',
            'password' => 'senha-forte-1',
            'password_confirmation' => 'senha-forte-1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_login_returns_jwt_and_rejects_bad_password(): void
    {
        $this->registerUser('bia@mordomus.test');

        $this->postJson('/api/v1/identity/auth/login', [
            'email' => 'bia@mordomus.test',
            'password' => 'senha-forte-1',
        ])->assertOk()->assertJsonPath('token_type', 'bearer');

        $this->postJson('/api/v1/identity/auth/login', [
            'email' => 'bia@mordomus.test',
            'password' => 'senha-errada-99',
        ])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_switch_tenant_issues_new_token_with_new_tid(): void
    {
        $auth = $this->registerUser('carlos@mordomus.test');
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/identity/tenants', ['name' => 'Casa de Praia']);

        $second->assertCreated();
        $secondId = $second->json('data.id');
        $this->assertNotSame($auth['active_tenant'], $secondId);

        $switched = $this->withHeaders($headers)
            ->postJson('/api/v1/identity/auth/switch-tenant', ['tenant_id' => $secondId]);

        $switched->assertOk();
        $this->assertSame($secondId, $switched->json('active_tenant'));

        $claims = $this->claimsOf($switched->json('access_token'));
        $this->assertSame($secondId, $claims['tid']);
        $this->assertCount(2, $claims['tenants']);

        $this->withHeaders($headers)
            ->postJson('/api/v1/identity/auth/switch-tenant', ['tenant_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'membership_required');
    }

    public function test_refresh_rotates_token_and_rejects_reuse(): void
    {
        $auth = $this->registerUser('duda@mordomus.test');

        $refreshed = $this->postJson('/api/v1/identity/auth/refresh', [
            'refresh_token' => $auth['refresh_token'],
        ]);

        $refreshed->assertOk();
        $this->assertNotSame($auth['refresh_token'], $refreshed->json('refresh_token'));
        $this->assertNotEmpty($refreshed->json('access_token'));

        // reuso do token antigo → família revogada (detecção de roubo)
        $this->postJson('/api/v1/identity/auth/refresh', [
            'refresh_token' => $auth['refresh_token'],
        ])->assertStatus(401)->assertJsonPath('error.code', 'refresh_token_reused');
    }

    public function test_protected_routes_require_token(): void
    {
        $this->getJson('/api/v1/identity/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $auth = $this->registerUser('elisa@mordomus.test');
        $headers = ['Authorization' => 'Bearer '.$auth['access_token'], 'Accept' => 'application/json'];

        $this->withHeaders($headers)->postJson('/api/v1/identity/auth/logout', [
            'refresh_token' => $auth['refresh_token'],
        ])->assertOk();

        $this->postJson('/api/v1/identity/auth/refresh', [
            'refresh_token' => $auth['refresh_token'],
        ])->assertStatus(401);
    }

    /** AC — cobertura de autenticação: access token expirado → 401. */
    public function test_expired_access_token_is_rejected_with_401(): void
    {
        $auth = $this->registerUser('expira@mordomus.test', 'Expira', 'Casa Expira');
        $user = User::query()->findOrFail($auth['user']['id']);

        $claims = app(JwtIssuerService::class)->claims(
            $user->id,
            $auth['active_tenant'],
            [$auth['active_tenant']],
            now()->subHours(2)->timestamp,
        );

        $expired = JWT::encode($claims, (string) config('jwt.private_key'), 'RS256', config('jwt.kid'));

        $this->withHeaders(['Authorization' => 'Bearer '.$expired, 'Accept' => 'application/json'])
            ->getJson('/api/v1/identity/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    /** AC — cobertura de autenticação: refresh token expirado → 401. */
    public function test_expired_refresh_token_is_rejected_with_401(): void
    {
        $auth = $this->registerUser('refresh-velho@mordomus.test');

        RefreshToken::query()
            ->where('token_hash', hash('sha256', $auth['refresh_token']))
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/identity/auth/refresh', [
            'refresh_token' => $auth['refresh_token'],
        ])->assertStatus(401)->assertJsonPath('error.code', 'refresh_token_expired');
    }
}
