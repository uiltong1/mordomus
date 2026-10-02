<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Mordomus\Notification\Models\NotificationLog;

/**
 * Histórico do morador: recorte, filtro e o motivo da falha.
 */
class NotificationLogCrudTest extends FeatureTestCase
{
    private string $tenantId;

    private string $userId;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('hist@mordomus.test', 'Hist', 'Casa do Hist');
        $this->tenantId = $auth['active_tenant'];
        $this->userId = $auth['user']['id'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_history_lists_what_the_morador_received(): void
    {
        $this->log(['subject' => 'Conta de energia vence amanhã']);
        $this->log(['subject' => 'Trocar o filtro do ar']);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/notification/logs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure([
                'data' => [['id', 'channel', 'status', 'subject', 'template', 'sent_at', 'error']],
                'meta' => ['page', 'per_page', 'total', 'last_page'],
            ]);
    }

    public function test_the_history_shows_the_failure_and_its_reason(): void
    {
        // A resposta à pergunta "por que não recebi?" é o que o histórico
        // precisa dar: só listar o que deu certo não responde nada.
        $this->log([
            'status' => NotificationLog::STATUS_FAILED,
            'error' => 'O serviço de push recusou a mensagem com 500.',
            'sent_at' => null,
        ]);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/notification/logs')
            ->assertOk()
            ->assertJsonPath('data.0.status', NotificationLog::STATUS_FAILED)
            ->assertJsonPath('data.0.error', 'O serviço de push recusou a mensagem com 500.')
            ->assertJsonPath('data.0.sent_at', null);
    }

    public function test_the_history_is_the_cut_of_the_caller(): void
    {
        $this->log(['subject' => 'Do dono']);

        $mate = $this->registerUser('colega@mordomus.test', 'Colega', 'Casa do Colega');
        $this->withTenantContext($this->tenantId, function () use ($mate): void {
            NotificationLog::create([
                'tenant_id' => $this->tenantId,
                'user_id' => $mate['user']['id'],
                'channel' => NotificationLog::CHANNEL_PUSH,
                'template' => 'mordomus::schedule.due',
                'subject' => 'Do colega',
                'body' => [],
                'dedupe_key' => 'schedule.due:outro:push:'.$mate['user']['id'],
                'status' => NotificationLog::STATUS_SENT,
                'sent_at' => CarbonImmutable::now('UTC'),
            ]);
        });

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/notification/logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Do dono');
    }

    public function test_the_filter_by_channel_narrows_the_cut(): void
    {
        $this->log(['channel' => NotificationLog::CHANNEL_PUSH]);
        $this->log(['channel' => NotificationLog::CHANNEL_EMAIL]);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/notification/logs?channel=email')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', NotificationLog::CHANNEL_EMAIL);
    }

    public function test_a_channel_outside_the_enum_is_rejected(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/notification/logs?channel=telepatia')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['channel']]]);
    }

    public function test_the_history_paginates(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->log();
        }

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/notification/logs?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_the_instant_of_the_line_is_answered_in_the_timezone_of_the_house(): void
    {
        $this->log(['available_at' => CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC')]);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/notification/logs')
            ->assertOk()
            // 12:00 UTC são 09:00 em São Paulo, e é o horário do morador que
            // a tela mostra.
            ->assertJsonPath('data.0.available_at', '2026-03-15T09:00:00-03:00');
    }

    public function test_the_history_demands_a_token(): void
    {
        $this->getJson('/api/v1/notification/logs')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $attributes */
    private function log(array $attributes = []): NotificationLog
    {
        static $sequence = 0;
        $sequence++;

        return $this->withTenantContext($this->tenantId, fn (): NotificationLog => NotificationLog::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'channel' => NotificationLog::CHANNEL_PUSH,
            'template' => 'mordomus::schedule.due',
            'subject' => 'A tarefa vence amanhã',
            'body' => ['title' => 'Manutenção', 'lines' => ['Trocar o filtro']],
            'dedupe_key' => 'schedule.due:'.$sequence.':push:'.$this->userId,
            'status' => NotificationLog::STATUS_SENT,
            'available_at' => CarbonImmutable::now('UTC'),
            'sent_at' => CarbonImmutable::now('UTC'),
            'error' => null,
            ...$attributes,
        ]));
    }
}
