<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mordomus\Financial\Events\EventName as FinancialEventName;
use Mordomus\Identity\Events\EventName as IdentityEventName;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Notification\Contracts\Services\NotificationConsumerServiceInterface;
use Mordomus\Notification\Jobs\DeliverNotification;
use Mordomus\Notification\Models\DeviceToken;
use Mordomus\Notification\Models\NotificationLog;
use Mordomus\Notification\Models\NotificationPreference;
use Mordomus\Scheduling\Events\EventName as ScheduleEventName;

/**
 * Consumo do envelope: destinatário, canal, dedupe e adiamento.
 *
 * O consumidor é chamado com o envelope direto em vez de pela fila: o que
 * importa aqui é a linha que ele grava, e o despacho da entrega é conferido com
 * `Queue::fake`, que é onde o atraso do adiamento aparece.
 */
class NotificationConsumerTest extends FeatureTestCase
{
    private string $tenantId;

    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('dono@mordomus.test', 'Dono', 'Casa do Dono');
        $this->tenantId = $auth['active_tenant'];
        $this->ownerId = $auth['user']['id'];

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
        Queue::fake([DeliverNotification::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ destinatário

    public function test_a_due_task_reaches_every_active_member_of_the_house(): void
    {
        $mate = $this->addMember('moradora@mordomus.test');
        $this->device($this->ownerId, 'https://push.test/dono');
        $this->device($mate, 'https://push.test/moradora');

        $queued = $this->consume($this->scheduleDue());

        $this->assertSame(2, $queued);
        $this->assertEqualsCanonicalizing(
            [$this->ownerId, $mate],
            $this->logs()->pluck('user_id')->all(),
        );
    }

    public function test_a_split_reaches_only_the_share_holders(): void
    {
        $out = $this->addMember('defora@mordomus.test');
        $this->device($this->ownerId, 'https://push.test/dono');
        $this->device($out, 'https://push.test/defora');

        $queued = $this->consume($this->splitComputed($this->ownerId));

        $this->assertSame(1, $queued);
        $this->assertSame([$this->ownerId], $this->logs()->pluck('user_id')->all());
    }

    public function test_the_newcomer_is_the_one_who_hears_about_the_join(): void
    {
        $this->device($this->ownerId, 'https://push.test/dono');

        $queued = $this->consume([
            'event' => IdentityEventName::MEMBER_ADDED,
            'event_id' => $this->eventId(),
            'occurred_at' => '2026-03-15T12:00:00+00:00',
            'tenant_id' => $this->tenantId,
            'payload' => [
                'tenant_id' => $this->tenantId,
                'user_id' => $this->ownerId,
                'role' => 'owner',
                'home_name' => 'Casa do Dono',
            ],
        ]);

        $this->assertSame(1, $queued);
        $this->assertSame([$this->ownerId], $this->logs()->pluck('user_id')->all());
    }

    public function test_an_event_of_another_house_is_not_consumed(): void
    {
        $this->device($this->ownerId, 'https://push.test/dono');

        $envelope = $this->scheduleDue();
        $envelope['tenant_id'] = $this->otherTenantId();

        $this->assertSame(0, $this->consume($envelope));
        $this->assertCount(0, $this->logs());
    }

    public function test_an_event_this_module_does_not_know_is_ignored(): void
    {
        $this->device($this->ownerId, 'https://push.test/dono');

        $envelope = $this->scheduleDue();
        $envelope['event'] = 'tenant.something_else';

        $this->assertSame(0, $this->consume($envelope));
        $this->assertCount(0, $this->logs());
    }

    public function test_an_envelope_without_a_tenant_is_ignored(): void
    {
        $envelope = $this->scheduleDue();
        unset($envelope['tenant_id']);

        $this->assertSame(0, $this->consume($envelope));
        $this->assertCount(0, $this->logs());
    }

    // ------------------------------------------------------------------ canal

    public function test_instant_without_a_signature_queues_nothing(): void
    {
        // Sem assinatura de push não há para quem mandar: inventar a linha
        // seria um aviso que nunca existiu.
        $this->assertSame(0, $this->consume($this->scheduleDue()));
        $this->assertCount(0, $this->logs());
    }

    public function test_instant_goes_by_push_to_the_push_queue(): void
    {
        $this->device($this->ownerId, 'https://push.test/dono');

        $this->consume($this->scheduleDue());

        $log = $this->logs()->firstOrFail();
        $this->assertSame(NotificationLog::CHANNEL_PUSH, $log->channel);
        $this->assertSame(NotificationLog::STATUS_QUEUED, $log->status);
        $this->assertSame(config('notification.queues.push'), $this->pushedJob()->queue);
    }

    public function test_daily_goes_by_email_without_needing_a_signature(): void
    {
        $this->preference(['digest' => NotificationPreference::DIGEST_DAILY, 'preferred_hour' => '08:00']);

        $this->assertSame(1, $this->consume($this->scheduleDue()));

        $log = $this->logs()->firstOrFail();
        $this->assertSame(NotificationLog::CHANNEL_EMAIL, $log->channel);
        $this->assertSame(config('notification.queues.emails'), $this->pushedJob()->queue);
    }

    // ---------------------------------------------------------------- dedupe

    public function test_the_same_envelope_delivered_twice_queues_once(): void
    {
        $this->device($this->ownerId, 'https://push.test/dono');
        $envelope = $this->scheduleDue();

        $this->assertSame(1, $this->consume($envelope));
        $this->assertSame(0, $this->consume($envelope));

        $this->assertCount(1, $this->logs());
        $this->assertCount(1, Queue::pushed(DeliverNotification::class));
    }

    public function test_two_occurrences_of_the_same_event_are_two_notifications(): void
    {
        $this->device($this->ownerId, 'https://push.test/dono');

        $this->consume($this->scheduleDue());

        $other = $this->scheduleDue();
        $other['payload']['dedupe_key'] = 'trigger:'.$this->otherTenantId();
        $this->consume($other);

        $this->assertCount(2, $this->logs());
    }

    public function test_the_same_event_for_two_residents_is_two_notifications(): void
    {
        $mate = $this->addMember('moradora@mordomus.test');
        $this->device($this->ownerId, 'https://push.test/dono');
        $this->device($mate, 'https://push.test/moradora');

        $this->assertSame(2, $this->consume($this->scheduleDue()));
        $this->assertCount(2, $this->logs());
    }

    // ------------------------------------------------------------ quiet hours

    public function test_a_notice_that_arrives_at_night_is_deferred_and_not_cancelled(): void
    {
        $this->device($this->ownerId, 'https://push.test/dono');
        $this->preference(['quiet_start' => '22:00', 'quiet_end' => '07:00']);

        // 23:00 em São Paulo são 02:00 UTC do dia 16.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-16 02:00:00', 'UTC'));

        $this->assertSame(1, $this->consume($this->scheduleDue()));

        $log = $this->logs()->firstOrFail();

        $this->assertSame(NotificationLog::STATUS_QUEUED, $log->status);
        $this->assertSame(
            '2026-03-16T10:00:00+00:00',
            $log->available_at->toIso8601String(),
            'o aviso das 23:00 tem de sair às 07:00 de São Paulo',
        );
        $this->assertSame(8 * 3600, $this->pushedJob()->delay);
    }

    public function test_a_notice_outside_the_window_goes_out_now(): void
    {
        $this->device($this->ownerId, 'https://push.test/dono');
        $this->preference(['quiet_start' => '22:00', 'quiet_end' => '07:00']);

        $this->assertSame(1, $this->consume($this->scheduleDue()));

        $this->assertTrue($this->logs()->firstOrFail()->available_at->equalTo(CarbonImmutable::now('UTC')));
        $this->assertSame(0, $this->pushedJob()->delay);
    }

    public function test_the_daily_digest_waits_for_the_preferred_hour(): void
    {
        $this->preference([
            'digest' => NotificationPreference::DIGEST_DAILY,
            'preferred_hour' => '08:00',
            'quiet_start' => '22:00',
            'quiet_end' => '07:00',
        ]);

        $this->assertSame(1, $this->consume($this->scheduleDue()));

        $this->assertSame(
            '2026-03-16T11:00:00+00:00',
            $this->logs()->firstOrFail()->available_at->toIso8601String(),
        );
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function consume(array $envelope): int
    {
        return app(NotificationConsumerServiceInterface::class)->consume($envelope);
    }

    /** @return Collection<int, NotificationLog> */
    private function logs(): Collection
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): Collection => NotificationLog::query()->get(),
        );
    }

    private function pushedJob(): DeliverNotification
    {
        $pushed = Queue::pushed(DeliverNotification::class);

        $this->assertCount(1, $pushed, 'a entrega foi despachada uma única vez');

        return $pushed->first();
    }

    private function device(string $userId, string $endpoint): DeviceToken
    {
        return $this->withTenantContext($this->tenantId, fn (): DeviceToken => DeviceToken::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'platform' => DeviceToken::PLATFORM_WEB,
            'p256dh' => 'p256dh-de-teste',
            'auth' => 'auth-de-teste',
            'last_seen_at' => CarbonImmutable::now('UTC'),
        ]));
    }

    /** @param array<string, mixed> $attributes */
    private function preference(array $attributes): NotificationPreference
    {
        return $this->withTenantContext($this->tenantId, fn (): NotificationPreference => NotificationPreference::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->ownerId,
            ...$attributes,
        ]));
    }

    private function addMember(string $email): string
    {
        $user = User::factory()->create(['email' => $email]);

        $this->withTenantContext($this->tenantId, fn () => Membership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]));

        return (string) $user->id;
    }

    private function otherTenantId(): string
    {
        return $this->registerUser('outra@mordomus.test', 'Outra', 'Casa Outra')['active_tenant'];
    }

    /** @return array<string, mixed> */
    private function scheduleDue(): array
    {
        return [
            'event' => ScheduleEventName::SCHEDULE_DUE,
            'event_id' => $this->eventId(),
            'occurred_at' => '2026-03-15T12:00:00+00:00',
            'tenant_id' => $this->tenantId,
            'payload' => [
                'tenant_id' => $this->tenantId,
                'subject_type' => 'asset',
                'subject_id' => $this->eventId(),
                'title' => 'Trocar o filtro do ar',
                'due_at' => '2026-03-15T18:00:00+00:00',
                'dedupe_key' => 'trigger:'.$this->eventId(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function splitComputed(string $userId): array
    {
        return [
            'event' => FinancialEventName::EXPENSE_SPLIT_COMPUTED,
            'event_id' => $this->eventId(),
            'occurred_at' => '2026-03-15T12:00:00+00:00',
            'tenant_id' => $this->tenantId,
            'payload' => [
                'tenant_id' => $this->tenantId,
                'expense_id' => $this->eventId(),
                'expense_name' => 'Compras da casa',
                'total_cents' => 9000,
                'currency' => 'BRL',
                'dedupe_key' => 'expense:'.$this->eventId(),
                'shares' => [
                    ['user_id' => $userId, 'percent' => 100, 'amount_cents' => 9000],
                ],
            ],
        ];
    }

    private function eventId(): string
    {
        return Str::ulid()->toBase32();
    }
}
