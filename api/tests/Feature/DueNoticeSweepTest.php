<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Mordomus\Financial\Models\Bill;
use Mordomus\Scheduling\Contracts\Services\OccurrenceMaterializerServiceInterface;
use Mordomus\Scheduling\Events\EventName;
use Mordomus\Scheduling\Jobs\PublishEvent;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\ScheduleEvent;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Varrimento de avisos de 15 em 15 minutos (T3.2.6).
 *
 * O caso que o AC pede — aviso antecipado e offsets na data certa — é
 * sensível à hora: as ocorrências nascem na `preferred_hour` da residência
 * (09:00 em São Paulo, 12:00 UTC), então o relógio é congelado em torno dos
 * instantes que decidem cada passada.
 *
 * Com a regra padrão (20 dias a partir de 20/03), o vencimento cai em
 * 09/04 às 12:00 UTC.
 */
class DueNoticeSweepTest extends FeatureTestCase
{
    private const DUE = '2026-04-09';

    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('avisos@mordomus.test', 'Avisos', 'Casa Avisos');
        $this->tenantId = $auth['active_tenant'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        $roomId = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Sala'])
            ->assertCreated()
            ->json('data.id');

        $this->assetId = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets', ['room_id' => $roomId, 'name' => 'Ar-condicionado'])
            ->assertCreated()
            ->json('data.id');

        $this->freezeAt('2026-03-20 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_advance_notice_fires_exactly_the_configured_days_earlier(): void
    {
        $this->createRule(['advance_notice_days' => 2]);
        $this->materialize();
        $this->assertSame([self::DUE, '2026-04-29'], $this->dates());

        $this->sweepAt('2026-04-07 11:59:00');
        $this->assertSame([], $this->publishedKinds());

        $this->sweepAt('2026-04-07 12:00:00');
        $this->assertSame(['advance_notice'], $this->publishedKinds());
    }

    public function test_rule_without_advance_notice_is_announced_on_the_due_date(): void
    {
        $this->createRule();
        $this->materialize();

        $this->sweepAt('2026-04-09 11:59:00');
        $this->assertSame([], $this->publishedKinds());

        $this->sweepAt('2026-04-09 12:00:00');
        $this->assertSame(['due'], $this->publishedKinds());
    }

    public function test_escalated_publishes_one_notice_per_offset(): void
    {
        $this->createRule([
            'type' => TriggerConfig::TYPE_ESCALATED,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'custom_offsets' => [-3, 0, 1],
        ]);
        $this->materialize();

        // 20/03 + 30 dias ⇒ vencimento em 19/04 às 12:00 UTC.
        $this->sweepAt('2026-04-16 11:59:00');
        $this->assertSame([], $this->publishedKinds());

        $this->sweepAt('2026-04-16 12:00:00');
        $this->assertSame(['offset:-3'], $this->publishedKinds());

        $this->sweepAt('2026-04-19 12:00:00');
        $this->assertSame(['offset:-3', 'offset:0'], $this->publishedKinds());

        // O alerta de atraso vive depois de `due_at`, quando a ocorrência já
        // virou `overdue` — é o offset positivo que impede o varrimento de
        // descartá-la antes de avisar.
        $this->sweepAt('2026-04-20 12:00:00');
        $this->assertSame(['offset:-3', 'offset:0', 'offset:1'], $this->publishedKinds());
    }

    /** O AC de idempotência na prática: 10 passadas do varrimento, 1 aviso. */
    public function test_sweeping_ten_times_publishes_each_notice_once(): void
    {
        $this->createRule();
        $this->materialize();

        for ($run = 0; $run < 10; $run++) {
            $this->sweepAt('2026-04-09 13:00:00');
        }

        $this->assertSame(['due'], $this->publishedKinds());
        $this->assertSame(1, $this->trailCount(ScheduleEvent::NOTIFIED));
    }

    public function test_the_dedupe_key_is_stable_across_sweeps(): void
    {
        $this->createRule();
        $this->materialize();

        $this->sweepAt('2026-04-09 13:00:00');
        $this->sweepAt('2026-04-09 13:15:00');

        // A chave é função do aviso, não da passada: é o que permite ao
        // consumidor (T6.1) descartar a reentrega do at-least-once.
        $this->assertSame(
            ["tenant:{$this->tenantId}:{$this->occurrenceId(self::DUE)}:due"],
            array_column($this->publishedNotices(), 'dedupe_key'),
        );
    }

    public function test_notice_moves_the_occurrence_to_notified(): void
    {
        $this->createRule(['advance_notice_days' => 3]);
        $this->materialize();
        $this->sweepAt('2026-04-06 13:00:00');

        // Com antecedência a ocorrência fica `notified` até o vencimento: o
        // morador já foi avisado e a data ainda não passou.
        $this->assertSame(JobSchedule::STATUS_NOTIFIED, $this->occurrenceStatus(self::DUE));
        $this->assertSame(
            '2026-04-06T09:00:00-03:00',
            $this->withTenantContext($this->tenantId, fn (): ?string => JobSchedule::query()
                ->where('scheduled_for', self::DUE)
                ->firstOrFail()
                ->notified_at?->timezone('America/Sao_Paulo')
                ->toIso8601String()),
        );
    }

    public function test_past_due_occurrence_becomes_overdue_and_still_accepts_check_in(): void
    {
        $this->createRule();
        $this->materialize();

        $this->sweepAt('2026-04-09 09:00:00');
        $this->assertSame(JobSchedule::STATUS_PENDING, $this->occurrenceStatus(self::DUE));

        $this->sweepAt('2026-04-09 13:00:00');
        $this->assertSame(JobSchedule::STATUS_OVERDUE, $this->occurrenceStatus(self::DUE));

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId(self::DUE).'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', JobSchedule::STATUS_COMPLETED);
    }

    public function test_inactive_rule_is_not_announced(): void
    {
        $this->createRule();
        $this->materialize();

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$this->ruleId(), ['is_active' => false])
            ->assertOk();

        $this->sweepAt('2026-04-09 13:00:00');

        $this->assertSame([], $this->publishedKinds());
    }

    public function test_completed_occurrence_is_not_announced(): void
    {
        $this->createRule();
        $this->materialize();

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId(self::DUE).'/complete')
            ->assertOk();

        $this->sweepAt('2026-04-09 13:00:00');

        $this->assertSame([], $this->publishedKinds());
    }

    public function test_notice_carries_the_contract_of_the_consumer(): void
    {
        Queue::fake([PublishEvent::class]);

        $this->createRule();
        $this->materialize();
        $this->sweepAt('2026-04-09 13:00:00');

        Queue::assertPushed(PublishEvent::class, function (PublishEvent $job): bool {
            $payload = $job->envelope['payload'];

            return $job->envelope['event'] === EventName::SCHEDULE_DUE
                && $job->envelope['tenant_id'] === $this->tenantId
                && $payload['schedule_id'] === $this->occurrenceId(self::DUE)
                && $payload['subject_type'] === TriggerConfig::SUBJECT_ASSET
                && $payload['subject_id'] === $this->assetId
                && $payload['title'] === 'Limpeza do filtro'
                && $payload['kind'] === 'due'
                && $payload['offsets'] === null
                && $payload['due_at'] === '2026-04-09T12:00:00+00:00';
        });
    }

    public function test_occurrence_of_a_bill_is_announced_without_date_math_in_the_financial(): void
    {
        Queue::fake([PublishEvent::class]);

        // O AC do `subject_type=bill`: a ocorrência é do Scheduling, e o
        // `subject_id` publicado é o da conta — o Financial não calcula data.
        $billId = $this->withTenantContext($this->tenantId, fn (): string => Bill::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Conta de energia',
            'kind' => Bill::KIND_FIXED,
            'amount' => '210.00',
        ])->id);

        $this->withTenantContext($this->tenantId, function () use ($billId): void {
            TriggerConfig::create([
                'tenant_id' => $this->tenantId,
                'subject_type' => TriggerConfig::SUBJECT_BILL,
                'bill_id' => $billId,
                'title' => 'Conta de energia',
                'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
                'day_of_month' => 10,
                'advance_notice_days' => 3,
            ]);

            app(OccurrenceMaterializerServiceInterface::class)->materialize($this->tenantId, 45);
        });

        $this->assertSame(['2026-04-10'], $this->dates());

        $this->sweepAt('2026-04-07 12:00:00');
        $this->assertSame(['advance_notice'], $this->publishedKinds());

        // A mesma fila leva o aviso republicado pelo Financial (`bill.due`),
        // então a verificação olha só o envelope do Scheduling.
        Queue::assertPushed(PublishEvent::class, function (PublishEvent $job) use ($billId): bool {
            return ($job->envelope['event'] ?? null) === EventName::SCHEDULE_DUE
                && ($job->envelope['payload']['subject_type'] ?? null) === TriggerConfig::SUBJECT_BILL
                && ($job->envelope['payload']['subject_id'] ?? null) === $billId;
        });
    }

    // -------------------------------------------------------------- helpers

    private function freezeAt(string $instantUtc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instantUtc, 'UTC'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createRule(array $payload = []): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => TriggerConfig::SUBJECT_ASSET,
            'subject_id' => $this->assetId,
            'title' => 'Limpeza do filtro',
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 20,
            'interval_unit' => 'days',
            ...$payload,
        ])->assertCreated();
    }

    private function materialize(): void
    {
        $this->artisan('scheduling:materialize')->assertSuccessful();
    }

    private function sweepAt(string $instantUtc): void
    {
        $this->freezeAt($instantUtc);
        $this->artisan('scheduling:publish-due')->assertSuccessful();
    }

    private function ruleId(): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) TriggerConfig::query()->value('id'),
        );
    }

    private function occurrenceId(string $scheduledFor): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) JobSchedule::query()->where('scheduled_for', $scheduledFor)->value('id'),
        );
    }

    private function occurrenceStatus(string $scheduledFor): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) JobSchedule::query()->where('scheduled_for', $scheduledFor)->value('status'),
        );
    }

    /** @return list<string> */
    private function dates(): array
    {
        return $this->withTenantContext($this->tenantId, fn (): array => JobSchedule::query()
            ->orderBy('scheduled_for')
            ->get()
            ->map(fn (JobSchedule $occurrence): string => $occurrence->scheduled_for->format('Y-m-d'))
            ->all());
    }

    /**
     * `kind` de cada aviso, na ordem de publicação; o offset entra no rótulo
     * porque é ele que distingue um aviso do outro no `ESCALATED`.
     *
     * @return list<string>
     */
    private function publishedKinds(): array
    {
        return array_map(
            fn (array $notice): string => $notice['kind'] === 'offset' ? 'offset:'.$notice['offset'] : $notice['kind'],
            $this->publishedNotices(),
        );
    }

    /** @return list<array<string, mixed>> */
    private function publishedNotices(): array
    {
        return $this->withTenantContext($this->tenantId, fn (): array => ScheduleEvent::query()
            ->where('event', ScheduleEvent::NOTIFIED)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ScheduleEvent $event): array => $event->payload)
            ->all());
    }

    private function trailCount(string $event): int
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): int => ScheduleEvent::query()->where('event', $event)->count(),
        );
    }
}
