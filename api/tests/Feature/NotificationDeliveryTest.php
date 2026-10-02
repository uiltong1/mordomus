<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mordomus\Identity\Models\User;
use Mordomus\Notification\Contracts\Services\NotificationDeliveryServiceInterface;
use Mordomus\Notification\Contracts\Services\PushChannelInterface;
use Mordomus\Notification\Jobs\DeliverNotification;
use Mordomus\Notification\Mail\NotificationMail;
use Mordomus\Notification\Models\DeviceToken;
use Mordomus\Notification\Models\NotificationLog;
use Tests\Support\FakePushChannel;

/**
 * Entrega: fan-out por assinatura, assinatura morta, adiamento e DLQ.
 *
 * O canal de push é o duplo porque é a única fronteira que mente: o provedor
 * real exige VAPID, cifra e rede, e o que importa aqui é o que o monólito faz
 * com a resposta dele.
 */
class NotificationDeliveryTest extends FeatureTestCase
{
    private string $tenantId;

    private string $userId;

    private FakePushChannel $push;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('entrega@mordomus.test', 'Entrega', 'Casa da Entrega');
        $this->tenantId = $auth['active_tenant'];
        $this->userId = $auth['user']['id'];

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ push

    public function test_a_delivered_push_marks_the_line_as_sent(): void
    {
        $device = $this->device('https://push.test/um');
        $log = $this->log(['channel' => NotificationLog::CHANNEL_PUSH]);

        $this->deliver($log);

        $this->assertSame(NotificationLog::STATUS_SENT, $log->fresh()->status);
        $this->assertNotNull($log->fresh()->sent_at);
        $this->assertNull($log->fresh()->error);
        $this->assertCount(1, $this->push->sent);
        $this->assertSame($device->endpoint, $this->push->sent[0]['endpoint']);
    }

    public function test_the_card_that_arrives_carries_what_the_line_recorded(): void
    {
        $this->device('https://push.test/um');
        $log = $this->log([
            'channel' => NotificationLog::CHANNEL_PUSH,
            'body' => ['title' => 'Manutenção', 'lines' => ['Trocar o filtro'], 'tag' => 'schedule:abc'],
        ]);

        $this->deliver($log);

        $this->assertSame('Manutenção', $this->push->sent[0]['content']['title']);
        $this->assertSame(['Trocar o filtro'], $this->push->sent[0]['content']['lines']);
        $this->assertSame('schedule:abc', $this->push->sent[0]['content']['tag']);
    }

    public function test_a_gone_signature_is_removed_and_the_fan_out_goes_on(): void
    {
        $this->device('https://push.test/morta');
        $this->device('https://push/test/viva');
        $log = $this->log(['channel' => NotificationLog::CHANNEL_PUSH]);

        // 404/410 é `false`: a assinatura saiu, o resto do fan-out continua.
        $this->channel(['https://push.test/morta' => false, 'https://push/test/viva' => true]);
        $this->deliver($log);

        $this->assertSame(0, $this->deviceCount('https://push.test/morta'));
        $this->assertSame(1, $this->deviceCount('https://push/test/viva'));
        $this->assertSame(NotificationLog::STATUS_SENT, $log->fresh()->status);
    }

    public function test_a_provider_refusal_leaves_the_line_queued_for_retry(): void
    {
        $this->device('https://push.test/um');
        $log = $this->log(['channel' => NotificationLog::CHANNEL_PUSH]);
        $this->channel(['https://push.test/um' => 'refuse']);

        try {
            $this->deliver($log);
            $this->fail('a recusa do provedor tem de subir para o job repetir a tentativa');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('500', $exception->getMessage());
        }

        // `queued` é a prova de que a linha ainda está na fila de entrega: o
        // retry é do job, e a linha não vira `failed` antes das tentativas.
        $this->assertSame(NotificationLog::STATUS_QUEUED, $log->fresh()->status);
        $this->assertNull($log->fresh()->sent_at);
    }

    public function test_a_line_that_is_not_queued_is_not_delivered_again(): void
    {
        $this->device('https://push/test/um');
        $log = $this->log(['channel' => NotificationLog::CHANNEL_PUSH]);

        $this->deliver($log);
        $this->deliver($log);

        // A segunda passagem é o que aconteceria com um job repetido depois de
        // um sucesso parcial; sem o `sent_at` o morador receberia o aviso duas
        // vezes.
        $this->assertCount(1, $this->push->sent);
    }

    // ----------------------------------------------------------------- email

    public function test_a_digest_is_emailed_to_the_resident(): void
    {
        Mail::fake();

        $log = $this->log([
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'subject' => 'Conta de energia vence amanhã',
        ]);

        $this->deliver($log);

        Mail::assertSent(
            NotificationMail::class,
            fn (NotificationMail $mail): bool => $mail->hasTo('entrega@mordomus.test'),
        );

        $this->assertSame(NotificationLog::STATUS_SENT, $log->fresh()->status);
    }

    public function test_a_digest_of_a_resident_who_is_gone_fails_the_line(): void
    {
        Mail::fake();

        $log = $this->log(['channel' => NotificationLog::CHANNEL_EMAIL]);

        // A FK é `nullOnDelete`: a conta sai e a linha fica, que é o que permite
        // o histórico responder por que o e-mail não chegou.
        $this->withTenantContext($this->tenantId, fn () => User::query()
            ->whereKey($this->userId)
            ->delete());

        $this->assertNull($log->fresh()->user_id);

        $this->deliver($log);

        $this->assertSame(NotificationLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertStringContainsString('não existe mais', (string) $log->fresh()->error);
    }

    // -------------------------------------------------------------- adiamento

    public function test_a_line_whose_instant_has_not_come_goes_back_to_the_queue(): void
    {
        $this->device('https://push.test/um');
        Queue::fake([DeliverNotification::class]);

        $log = $this->log([
            'channel' => NotificationLog::CHANNEL_PUSH,
            'available_at' => CarbonImmutable::now('UTC')->addHours(8),
        ]);

        $this->deliver($log);

        Queue::assertPushed(DeliverNotification::class, function (DeliverNotification $job): bool {
            return $job->queue === config('notification.queues.push') && $job->delay === 8 * 3600;
        });

        // O adiamento não gasta uma das tentativas: elas são para o provedor.
        $this->assertSame(NotificationLog::STATUS_QUEUED, $log->fresh()->status);
        $this->assertCount(0, $this->push->sent);
    }

    public function test_a_line_whose_instant_has_passed_is_not_admitted_again(): void
    {
        $this->device('https://push.test/um');

        $log = $this->log([
            'channel' => NotificationLog::CHANNEL_PUSH,
            'available_at' => CarbonImmutable::now('UTC')->subMinutes(1),
        ]);

        $this->deliver($log);

        $this->assertCount(1, $this->push->sent);
    }

    // ------------------------------------------------------------------- DLQ

    public function test_the_job_marks_the_line_failed_when_the_attempts_run_out(): void
    {
        $log = $this->log(['channel' => NotificationLog::CHANNEL_PUSH]);

        $job = new DeliverNotification($log);
        $job->failed(new \RuntimeException('o serviço de push ficou fora do ar'));

        $failed = $log->fresh();
        $this->assertSame(NotificationLog::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('fora do ar', (string) $failed->error);
        $this->assertNull($failed->sent_at);
    }

    public function test_the_job_without_an_exception_still_leaves_a_reason(): void
    {
        $log = $this->log(['channel' => NotificationLog::CHANNEL_PUSH]);

        (new DeliverNotification($log))->failed(null);

        $this->assertSame(NotificationLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertNotEmpty($log->fresh()->error);
    }

    public function test_the_channel_of_the_line_does_not_choose_the_queue(): void
    {
        // `inapp` não é um canal de entrega: sair da lista é falha da linha, e
        // não um job que vai reprovar para sempre.
        $log = $this->log(['channel' => NotificationLog::CHANNEL_INAPP]);

        $this->deliver($log);

        $this->assertSame(NotificationLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertStringContainsString('Canal desconhecido', (string) $log->fresh()->error);
    }

    // ---------------------------------------------------------------- helpers

    /** Troca o canal de push; o que não for listado no mapa é aceito. */
    private function channel(array $endpoints = []): void
    {
        $this->push = new FakePushChannel($endpoints);

        $this->app->instance(PushChannelInterface::class, $this->push);
    }

    private function deliver(NotificationLog $log): void
    {
        $this->withTenantContext(
            $this->tenantId,
            fn () => app(NotificationDeliveryServiceInterface::class)->deliver($log),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function log(array $attributes): NotificationLog
    {
        $this->channel();

        return $this->withTenantContext($this->tenantId, fn (): NotificationLog => NotificationLog::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'channel' => NotificationLog::CHANNEL_PUSH,
            'template' => 'mordomus::schedule.due',
            'subject' => 'A tarefa vence amanhã',
            'body' => ['title' => 'Manutenção', 'lines' => ['Trocar o filtro'], 'tag' => 'schedule:abc'],
            'dedupe_key' => 'schedule.due:abc:push:'.$this->userId,
            'status' => NotificationLog::STATUS_QUEUED,
            'available_at' => CarbonImmutable::now('UTC'),
            ...$attributes,
        ]));
    }

    private function device(string $endpoint): DeviceToken
    {
        return $this->withTenantContext($this->tenantId, fn (): DeviceToken => DeviceToken::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'endpoint' => $endpoint,
            'platform' => DeviceToken::PLATFORM_WEB,
            'p256dh' => 'p256dh-de-teste',
            'auth' => 'auth-de-teste',
            'last_seen_at' => CarbonImmutable::now('UTC'),
        ]));
    }

    private function deviceCount(string $endpoint): int
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): int => DeviceToken::query()->where('endpoint', $endpoint)->count(),
        );
    }
}
