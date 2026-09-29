<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mordomus\Scheduling\Contracts\Services\OccurrenceMaterializerServiceInterface;
use Mordomus\Scheduling\Contracts\Services\OccurrenceRecalculatorServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Events\EventName;
use Mordomus\Scheduling\Jobs\MaterializeTenantOccurrences;
use Mordomus\Scheduling\Jobs\PublishEvent;
use Mordomus\Scheduling\Jobs\RecalculateNextOccurrence;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\ScheduleEvent;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Motor de agendamento na fila (T3.2.2–T3.2.7).
 *
 * A suíte roda com `QUEUE_CONNECTION=sync`, então o job do check-in executa
 * dentro da transação que o enfileirou: o cenário "concluir duas vezes" mede o
 * efeito real do recálculo, e não um mock da fila. O relógio é congelado —
 * qualquer data que dependesse do dia em que o teste roda falharia amanhã.
 */
class OccurrenceQueueTest extends FeatureTestCase
{
    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('motor@mordomus.test', 'Motor', 'Casa Motor');
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

        $this->freezeAt('2026-03-15 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------- materialização

    public function test_materialize_fills_the_window_of_every_active_rule(): void
    {
        $interval = $this->createRule();

        // A chamada vai direta: os campos de intervalo são `prohibited` em
        // `CALENDAR_MONTHLY` e o helper padrão os manda.
        $monthly = $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/trigger-configs', [
                'subject_type' => TriggerConfig::SUBJECT_ASSET,
                'subject_id' => $this->assetId,
                'title' => 'Conta de luz',
                'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
                'day_of_month' => 10,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->materialize(horizonDays: 75);

        // 15/03 + 10 dias, repetidas até sair da janela de 75 dias.
        $this->assertSame(
            ['2026-03-25', '2026-04-04', '2026-04-14', '2026-04-24', '2026-05-04', '2026-05-14', '2026-05-24'],
            $this->datesOf($interval),
        );

        // Dia 10 do mês: o dia 10 de março já passou, então o próximo é abril.
        $this->assertSame(['2026-04-10', '2026-05-10'], $this->datesOf($monthly));
    }

    public function test_materialize_resumes_from_the_last_occurrence(): void
    {
        $this->createRule();

        $this->materialize(horizonDays: 15);
        $this->assertSame(['2026-03-25'], $this->datesOf());

        $this->materialize(horizonDays: 45);
        $this->assertSame(['2026-03-25', '2026-04-04', '2026-04-14', '2026-04-24'], $this->datesOf());
    }

    /** O AC de idempotência: rodar o materializador 10x não cria nada além. */
    public function test_materializing_ten_times_creates_no_extra_occurrences(): void
    {
        $this->createRule();

        for ($run = 0; $run < 10; $run++) {
            $this->materialize(horizonDays: 45);
        }

        $this->assertCount(4, $this->datesOf());
        $this->assertSame(4, $this->trailCount(ScheduleEvent::CREATED));
    }

    public function test_inactive_rule_stops_producing_dates(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 15);

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$this->ruleId(), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->materialize(horizonDays: 45);

        $this->assertSame(['2026-03-25'], $this->datesOf());
    }

    public function test_post_completion_rule_waits_for_the_first_completion(): void
    {
        $this->createRule([
            'type' => TriggerConfig::TYPE_POST_COMPLETION,
            'interval_value' => 7,
            'interval_unit' => 'days',
            'recalculate_base' => TriggerConfig::RECALCULATE_COMPLETION,
        ]);

        $this->materialize(horizonDays: 45);

        // Sem âncora a regra ainda não rodou — inventar data seria calcular
        // fora do TriggerConfig.
        $this->assertSame([], $this->datesOf());
    }

    public function test_dates_that_passed_while_the_window_was_short_are_not_materialized(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 15);

        $this->freezeAt('2026-04-20 12:00:00');
        $this->materialize(horizonDays: 15);

        // O materializador avança a regra data a data e só grava as que ainda
        // estão por vir: 04/04 e 04/14 passaram enquanto ninguém rodava.
        $this->assertSame(['2026-03-25', '2026-04-24', '2026-05-04'], $this->datesOf());
    }

    // -------------------------------------------------- conclusão e recálculo

    public function test_completing_twice_creates_exactly_one_next_occurrence(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 10);
        $this->assertSame(['2026-03-25'], $this->datesOf());

        $this->complete('2026-03-25')->assertOk()->assertJsonPath('data.status', JobSchedule::STATUS_COMPLETED);
        $this->complete('2026-03-25')->assertOk()->assertJsonPath('data.status', JobSchedule::STATUS_COMPLETED);

        // 25/03 concluída ⇒ 04/04 é a única ocorrência nova.
        $this->assertSame(['2026-03-25', '2026-04-04'], $this->datesOf());
        $this->assertSame(1, $this->trailCount(ScheduleEvent::COMPLETED));
        $this->assertSame('2026-03-25', $this->triggerConfig()->last_base_date?->format('Y-m-d'));
    }

    public function test_recalculation_uses_the_due_date_base(): void
    {
        $this->createRule([
            'type' => TriggerConfig::TYPE_POST_COMPLETION,
            'interval_value' => 10,
            'interval_unit' => 'days',
            'recalculate_base' => TriggerConfig::RECALCULATE_DUE_DATE,
        ]);
        $this->seedOccurrence('2026-03-25');

        $this->complete('2026-03-25')->assertOk();

        // Base = dia do vencimento (25/03), e não o dia do check-in (15/03).
        $this->assertSame(['2026-03-25', '2026-04-04'], $this->datesOf());
        $this->assertSame('2026-03-25', $this->triggerConfig()->last_base_date?->format('Y-m-d'));
    }

    public function test_recalculation_uses_the_completion_base(): void
    {
        $this->createRule([
            'type' => TriggerConfig::TYPE_POST_COMPLETION,
            'interval_value' => 10,
            'interval_unit' => 'days',
            'recalculate_base' => TriggerConfig::RECALCULATE_COMPLETION,
        ]);
        $this->seedOccurrence('2026-03-25');

        $this->freezeAt('2026-03-20 12:00:00');
        $this->complete('2026-03-25')->assertOk();

        // Base = dia do check-in (20/03) + 10 dias.
        $this->assertSame(['2026-03-25', '2026-03-30'], $this->datesOf());
    }

    public function test_completion_dispatches_the_unique_recalculation_job(): void
    {
        Queue::fake([RecalculateNextOccurrence::class]);

        $this->createRule();
        $this->materialize(horizonDays: 10);
        $occurrenceId = $this->occurrenceId('2026-03-25');

        $this->complete('2026-03-25')->assertOk();

        Queue::assertPushed(RecalculateNextOccurrence::class, function (RecalculateNextOccurrence $job) use ($occurrenceId): bool {
            // O recálculo lê a ocorrência que a transação acabou de gravar;
            // enfileirado antes do commit ele veria uma linha ainda `pending`.
            return $job->tenantId === $this->tenantId
                && $job->occurrenceId === $occurrenceId
                && $job->scheduledFor === '2026-03-25'
                && $job->afterCommit === true
                && $job->uniqueId() === "tenant:{$this->tenantId}:{$occurrenceId}:2026-03-25";
        });
    }

    public function test_deactivated_rule_does_not_open_a_new_cycle(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 10);
        $this->complete('2026-03-25')->assertOk();

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$this->ruleId(), ['is_active' => false])
            ->assertOk();

        $this->complete('2026-04-04')->assertOk();

        $this->assertSame(['2026-03-25', '2026-04-04'], $this->datesOf());
    }

    public function test_recalculation_of_a_deleted_occurrence_is_a_no_op(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 10);
        $occurrenceId = $this->occurrenceId('2026-03-25');

        // Excluir a regra leva a ocorrência e a trilha junto, em cascata: o job
        // que ficou na fila não pode virar falha na DLQ.
        $this->withHeaders($this->headers)
            ->deleteJson('/api/v1/scheduling/trigger-configs/'.$this->ruleId())
            ->assertOk();

        $this->withTenantContext($this->tenantId, function () use ($occurrenceId): void {
            app(OccurrenceRecalculatorServiceInterface::class)->recalculate($this->tenantId, $occurrenceId);
        });

        $this->assertSame(0, $this->trailCount(ScheduleEvent::CREATED));
    }

    // ------------------------------------------------------------- dispensa

    public function test_skip_keeps_the_row_and_does_not_recalculate(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 10);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/skip')
            ->assertOk()
            ->assertJsonPath('data.status', JobSchedule::STATUS_SKIPPED);

        $this->assertSame(['2026-03-25'], $this->datesOf());
        $this->assertSame(1, $this->trailCount(ScheduleEvent::SKIPPED));
    }

    public function test_skipping_twice_is_idempotent(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 10);

        $url = '/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/skip';
        $this->withHeaders($this->headers)->postJson($url)->assertOk();
        $this->withHeaders($this->headers)->postJson($url)->assertOk();

        $this->assertSame(1, $this->trailCount(ScheduleEvent::SKIPPED));
    }

    public function test_completing_a_skipped_occurrence_is_a_conflict(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 10);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/skip')
            ->assertOk();

        $this->complete('2026-03-25')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'occurrence_already_finalized');
    }

    public function test_skipping_a_completed_occurrence_is_a_conflict(): void
    {
        $this->createRule();
        $this->materialize(horizonDays: 10);

        $this->complete('2026-03-25')->assertOk();

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/skip')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'occurrence_already_finalized');
    }

    // ------------------------------------------------------------ Publicação

    public function test_materialization_publishes_each_new_occurrence(): void
    {
        Queue::fake([PublishEvent::class]);

        $this->createRule();
        $this->materialize(horizonDays: 10);

        Queue::assertPushed(PublishEvent::class, function (PublishEvent $job): bool {
            return $job->envelope['event'] === EventName::OCCURRENCE_CREATED
                && $job->envelope['tenant_id'] === $this->tenantId
                && $job->envelope['payload']['subject_id'] === $this->assetId
                && $job->envelope['payload']['scheduled_for'] === '2026-03-25';
        });
    }

    public function test_events_travel_on_the_notification_queue(): void
    {
        Queue::fake([PublishEvent::class]);

        $this->createRule();
        $this->materialize(horizonDays: 10);

        Queue::assertPushedOn('mordomus:notification:events', PublishEvent::class);
    }

    public function test_occurrence_jobs_travel_on_the_scheduling_queue(): void
    {
        Queue::fake();

        $this->assertSame('mordomus:scheduling:occurrences', config('scheduling.queues.occurrences'));

        $this->artisan('scheduling:materialize')->assertSuccessful();

        Queue::assertPushedOn('mordomus:scheduling:occurrences', MaterializeTenantOccurrences::class);
    }

    // -------------------------------------------------------------- helpers

    private function freezeAt(string $instantUtc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instantUtc, 'UTC'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createRule(array $payload = []): string
    {
        $response = $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => TriggerConfig::SUBJECT_ASSET,
            'subject_id' => $this->assetId,
            'title' => 'Limpeza do filtro',
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 10,
            'interval_unit' => 'days',
            ...$payload,
        ]);

        $response->assertCreated();

        return (string) $response->json('data.id');
    }

    private function materialize(int $horizonDays): void
    {
        $this->artisan('scheduling:materialize', ['--days' => $horizonDays])->assertSuccessful();
    }

    private function complete(string $scheduledFor): TestResponse
    {
        return $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId($scheduledFor).'/complete');
    }

    /**
     * Ocorrência semeada direto, para o cenário que precisa de um dia de
     * vencimento que a materialização ainda não produziria.
     */
    private function seedOccurrence(string $scheduledFor): void
    {
        $this->withTenantContext($this->tenantId, function () use ($scheduledFor): void {
            app(OccurrenceMaterializerServiceInterface::class)->materializeDate(
                $this->triggerConfig(),
                app(TenantCalendarServiceInterface::class)->day($scheduledFor, $this->tenantId),
            );
        });
    }

    private function ruleId(): string
    {
        return (string) $this->triggerConfig()->id;
    }

    private function occurrenceId(string $scheduledFor): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) JobSchedule::query()
                ->where('scheduled_for', $scheduledFor)
                ->value('id'),
        );
    }

    private function triggerConfig(): TriggerConfig
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): TriggerConfig => TriggerConfig::query()->firstOrFail(),
        );
    }

    /** @return list<string> */
    private function datesOf(?string $triggerConfigId = null): array
    {
        return $this->withTenantContext($this->tenantId, function () use ($triggerConfigId): array {
            return JobSchedule::query()
                ->when($triggerConfigId !== null, fn ($query) => $query->where('trigger_config_id', $triggerConfigId))
                ->orderBy('scheduled_for')
                ->get()
                ->map(fn (JobSchedule $occurrence): string => $occurrence->scheduled_for->format('Y-m-d'))
                ->all();
        });
    }

    private function trailCount(string $event): int
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): int => ScheduleEvent::query()->where('event', $event)->count(),
        );
    }
}
