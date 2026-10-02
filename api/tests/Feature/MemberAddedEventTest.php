<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mordomus\Identity\Events\EventName;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Notification\Jobs\DeliverNotification;
use Mordomus\Notification\Models\DeviceToken;
use Mordomus\Notification\Models\NotificationLog;
use Mordomus\Scheduling\Events\QueuedEnvelope;
use Mordomus\Scheduling\Jobs\PublishEvent;
use Opis\JsonSchema\Validator;

/**
 * `tenant.member_added`: o contrato do evento, o caminho da publicação e a
 * ponte até o consumidor.
 *
 * O evento é o único que o Identity publica, e sai pelo aceite do convite —
 * dentro de uma transação de requisição, não de um job. O `PublishEvent` é o
 * que leva o envelope para a fila e o listener do Notification é quem o
 * consome; os dois lados precisam concordar sobre o formato, e é isso que o
 * teste amarra.
 */
class MemberAddedEventTest extends FeatureTestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new Validator;
    }

    public function test_the_event_has_a_schema(): void
    {
        $this->assertFileExists($this->schemaPath());
    }

    private function schemaPath(): string
    {
        return dirname(__DIR__, 3).'/packages/contracts/tenant.member_added.schema.json';
    }

    public function test_the_accepted_invitation_publishes_an_envelope_that_matches_the_contract(): void
    {
        Queue::fake([PublishEvent::class]);

        $owner = $this->registerUser('dono@mordomus.test', 'Dono', 'Casa do Convite');
        $tenantId = $owner['active_tenant'];
        $headers = ['Authorization' => 'Bearer '.$owner['access_token'], 'Accept' => 'application/json'];

        $invite = $this->withHeaders($headers)
            ->postJson("/api/v1/identity/tenants/{$tenantId}/invitations", ['email' => 'entrou@mordomus.test'])
            ->assertCreated();

        $guest = User::factory()->create(['email' => 'entrou@mordomus.test']);
        $this->asUser($guest)
            ->postJson('/api/v1/identity/invitations/'.$invite->json('token').'/accept')
            ->assertOk();

        $published = Queue::pushed(PublishEvent::class);

        $this->assertNotNull($published, 'o aceite de convite não publica nada');
        $this->assertCount(1, $published);

        $envelope = $published->first()->envelope;

        $this->assertSame(EventName::MEMBER_ADDED, $envelope['event']);
        $this->assertSame($tenantId, $envelope['tenant_id']);
        $this->assertSame((string) $guest->id, $envelope['payload']['user_id']);
        $this->assertSame(Role::MEMBER, $envelope['payload']['role']);
        $this->assertSame('Casa do Convite', $envelope['payload']['home_name']);

        $schema = json_decode((string) file_get_contents($this->schemaPath()));
        $result = $this->validator->validate(json_decode((string) json_encode($envelope)), $schema);

        $this->assertTrue(
            $result->isValid(),
            sprintf(
                "payload fora do contrato: %s\n%s",
                (string) $result->error(),
                (string) json_encode($envelope, JSON_PRETTY_PRINT),
            ),
        );
    }

    public function test_the_listener_consumes_the_envelope_that_the_job_publishes(): void
    {
        $owner = $this->registerUser('sino@mordomus.test', 'Sino', 'Casa do Sino');
        $tenantId = $owner['active_tenant'];
        $guest = User::factory()->create(['email' => 'veio@mordomus.test']);
        $this->signatureOf($tenantId, (string) $guest->id);

        // Só a entrega é falsada: o resto — publicar, o listener, o consumidor —
        // precisa rodar de verdade para o contrato do módulo valer alguma coisa.
        Queue::fake([DeliverNotification::class]);

        event(new QueuedEnvelope($this->envelope($tenantId, (string) $guest->id)));

        $log = $this->withTenantContext(
            $tenantId,
            fn (): ?NotificationLog => NotificationLog::query()->where('user_id', $guest->id)->first(),
        );

        $this->assertNotNull($log, 'o listener não gravou a linha do morador que entrou');
        $this->assertSame('mordomus::tenant.member_added', $log->template);
        $this->assertSame('Você entrou em Casa do Sino', $log->subject);
        $this->assertSame('mordomus:'.(string) $guest->id, $log->body['tag']);
    }

    public function test_the_same_envelope_twice_leaves_one_line(): void
    {
        $owner = $this->registerUser('sino@mordomus.test', 'Sino', 'Casa do Sino');
        $tenantId = $owner['active_tenant'];
        $guest = User::factory()->create(['email' => 'veio@mordomus.test']);
        $this->signatureOf($tenantId, (string) $guest->id);

        Queue::fake([DeliverNotification::class]);

        $envelope = $this->envelope($tenantId, (string) $guest->id);

        event(new QueuedEnvelope($envelope));
        event(new QueuedEnvelope($envelope));

        $this->assertSame(1, $this->withTenantContext(
            $tenantId,
            fn (): int => NotificationLog::query()->where('user_id', $guest->id)->count(),
        ));
    }

    public function test_a_malformed_envelope_is_dropped_without_touching_the_database(): void
    {
        $owner = $this->registerUser('sino@mordomus.test', 'Sino', 'Casa do Sino');
        $tenantId = $owner['active_tenant'];

        event(new QueuedEnvelope(['event' => EventName::MEMBER_ADDED, 'payload' => []]));

        $this->assertSame(0, $this->withTenantContext(
            $tenantId,
            fn (): int => NotificationLog::query()->count(),
        ));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * O canal padrão é push, e push sem assinatura não vira linha: quem está
     * entrando acabou de abrir a conta e provavelmente não assinou nada ainda.
     */
    private function signatureOf(string $tenantId, string $userId): void
    {
        $this->withTenantContext($tenantId, fn () => DeviceToken::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'endpoint' => 'https://push.test/'.$userId,
            'platform' => 'web',
            'p256dh' => 'p256dh-de-teste',
            'auth' => 'auth-de-teste',
            'last_seen_at' => CarbonImmutable::now('UTC'),
        ]));
    }

    /** @return array<string, mixed> */
    private function envelope(string $tenantId, string $userId): array
    {
        return [
            'event' => EventName::MEMBER_ADDED,
            'event_id' => Str::ulid()->toBase32(),
            'occurred_at' => '2026-03-15T12:00:00+00:00',
            'tenant_id' => $tenantId,
            'payload' => [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role' => Role::MEMBER,
                'home_name' => 'Casa do Sino',
            ],
        ];
    }
}
