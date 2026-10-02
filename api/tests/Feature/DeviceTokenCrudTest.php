<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Notification\Contracts\Services\PushChannelInterface;
use Mordomus\Notification\Models\DeviceToken;
use Tests\Support\FakePushChannel;

/**
 * Assinaturas de push do morador (ADR-001).
 */
class DeviceTokenCrudTest extends FeatureTestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    private string $tenantId;

    private string $userId;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('sino@mordomus.test', 'Sino', 'Casa do Sino');
        $this->tenantId = $auth['active_tenant'];
        $this->userId = $auth['user']['id'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        $this->channel();
        $this->withVapidPair();
    }

    /**
     * Par VAPID de verdade, porque o canal de verdade decide se está
     * configurado a partir do par e não de um flag de teste.
     */
    private function withVapidPair(): void
    {
        $private = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($private, $privatePem);

        $public = openssl_pkey_get_details($private);

        config([
            'notification.vapid.private_key' => $privatePem,
            'notification.vapid.public_key' => $public['key'],
        ]);
    }

    // ------------------------------------------------------------------ escrita

    public function test_a_signature_is_registered(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/notification/devices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.endpoint', self::ENDPOINT)
            ->assertJsonPath('data.platform', 'web');

        $this->assertSame(1, $this->deviceCount());
    }

    public function test_registering_the_same_signature_again_updates_it(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/notification/devices', $this->payload())->assertCreated();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 09:00:00', 'UTC'));

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/notification/devices', ['platform' => 'web'] + $this->payload())
            ->assertCreated();

        // O clique no sino pode acontecer quantas vezes o morador quiser: uma
        // linha só, senão o mesmo aviso sai duas vezes no mesmo navegador.
        $this->assertSame(1, $this->deviceCount());
        $this->assertSame(
            '2026-04-01T09:00:00+00:00',
            $this->device()->last_seen_at->toIso8601String(),
        );
    }

    public function test_a_signature_without_the_browser_keys_is_rejected(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/notification/devices', ['endpoint' => self::ENDPOINT])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['p256dh', 'auth']]]);
    }

    public function test_a_signature_of_another_resident_stays_out_of_the_list(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/notification/devices', $this->payload())->assertCreated();

        $mate = $this->registerUser('outro@mordomus.test', 'Outro', 'Casa do Outro');
        $mateHeaders = ['Authorization' => 'Bearer '.$mate['access_token'], 'Accept' => 'application/json'];

        $this->withHeaders($mateHeaders)
            ->getJson('/api/v1/notification/devices')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------- leitura

    public function test_the_list_does_not_carry_the_browser_secrets(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/notification/devices', $this->payload())->assertCreated();

        $body = $this->withHeaders($this->headers)->getJson('/api/v1/notification/devices')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertArrayNotHasKey('p256dh', $body['data'][0]);
        $this->assertArrayNotHasKey('auth', $body['data'][0]);
    }

    // ------------------------------------------------------------------- remoção

    public function test_a_signature_is_removed_by_its_endpoint(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/notification/devices', $this->payload())->assertCreated();

        $this->withHeaders($this->headers)
            ->deleteJson('/api/v1/notification/devices?endpoint='.urlencode(self::ENDPOINT))
            ->assertOk()
            ->assertJsonPath('data.removed', 1)
            ->assertJsonPath('data.endpoint', self::ENDPOINT);

        $this->assertSame(0, $this->deviceCount());
    }

    public function test_removing_a_signature_that_is_not_there_is_a_not_found(): void
    {
        $this->withHeaders($this->headers)
            ->deleteJson('/api/v1/notification/devices?endpoint='.urlencode(self::ENDPOINT))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'device_token_not_found');
    }

    public function test_removing_without_an_endpoint_is_rejected(): void
    {
        $this->withHeaders($this->headers)
            ->deleteJson('/api/v1/notification/devices')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['endpoint']]]);
    }

    public function test_a_mate_of_the_same_house_cannot_remove_the_signature_of_another(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/notification/devices', $this->payload())->assertCreated();

        // Mesmo endpoint, mesma casa, morador diferente: a assinatura é do
        // dono, e desinscrever a dele é responder por ele.
        $this->asUser($this->mate(), $this->tenantId)
            ->deleteJson('/api/v1/notification/devices?endpoint='.urlencode(self::ENDPOINT))
            ->assertStatus(404);

        $this->assertSame(1, $this->deviceCount());
    }

    // ------------------------------------------------------------------ teste

    public function test_the_test_send_reports_what_the_provider_answered(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/notification/devices', $this->payload())->assertCreated();
        $this->channel();

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/notification/devices/test')
            ->assertOk()
            ->assertJsonPath('data.devices', 1)
            ->assertJsonPath('data.delivered', 1)
            ->assertJsonPath('data.gone', 0);
    }

    public function test_the_test_send_drops_a_signature_the_provider_declared_gone(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/notification/devices', $this->payload())->assertCreated();
        $this->channel([self::ENDPOINT => false]);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/notification/devices/test')
            ->assertOk()
            ->assertJsonPath('data.delivered', 0)
            ->assertJsonPath('data.gone', 1);

        $this->assertSame(0, $this->deviceCount());
    }

    public function test_the_test_send_answers_503_when_the_environment_has_no_vapid_pair(): void
    {
        config(['notification.vapid.private_key' => null, 'notification.vapid.public_key' => null]);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/notification/devices/test')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'push_not_configured');
    }

    // ------------------------------------------------------------------ acesso

    public function test_everything_here_demands_a_token(): void
    {
        $this->getJson('/api/v1/notification/devices')->assertUnauthorized();
        $this->postJson('/api/v1/notification/devices', $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/v1/notification/devices?endpoint=x')->assertUnauthorized();
        $this->postJson('/api/v1/notification/devices/test')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- helpers

    private function mate(): User
    {
        $mate = User::factory()->create(['email' => 'colega@mordomus.test']);

        $this->withTenantContext($this->tenantId, fn () => Membership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $mate->id,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]));

        return $mate;
    }

    private function channel(array $endpoints = []): void
    {
        $this->app->instance(PushChannelInterface::class, new FakePushChannel($endpoints));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'platform' => 'web',
            'endpoint' => self::ENDPOINT,
            'p256dh' => 'p256dh-de-teste',
            'auth' => 'auth-de-teste',
        ];
    }

    private function deviceCount(): int
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): int => DeviceToken::query()->where('user_id', $this->userId)->count(),
        );
    }

    private function device(): DeviceToken
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): DeviceToken => DeviceToken::query()->where('user_id', $this->userId)->firstOrFail(),
        );
    }
}
