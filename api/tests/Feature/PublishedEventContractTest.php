<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Mordomus\Financial\Models\Bill;
use Mordomus\Scheduling\Events\EventName;
use Mordomus\Scheduling\Jobs\PublishEvent;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;
use Opis\JsonSchema\Validator;

/**
 * Contrato dos eventos publicados (ADR-009, T3.2.9).
 *
 * O schema é a fonte da verdade do payload: o teste deixa o motor inteiro rodar
 * e valida o envelope que de fato foi para a fila contra
 * `packages/contracts`. Campo renomeado, `subject_id` que deixou de ser o id
 * público ou `dedupe_key` instável quebram o build antes de chegarem ao
 * consumidor.
 *
 * Só o `PublishEvent` é falsado: o materializador e o recálculo precisam rodar
 * de verdade para que haja o que publicar.
 */
class PublishedEventContractTest extends FeatureTestCase
{
    private const CONTRACTS = '/packages/contracts';

    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    private string $assetId;

    private string $billId = '';

    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('contrato@mordomus.test', 'Contrato', 'Casa Contrato');
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

        $this->validator = new Validator;

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** Cada módulo publica os seus; o outro par é conferido no teste do Financial. */
    public function test_every_scheduling_event_has_a_schema(): void
    {
        $published = [
            EventName::OCCURRENCE_COMPLETED,
            EventName::OCCURRENCE_CREATED,
            EventName::SCHEDULE_DUE,
        ];

        $this->assertSame(
            $published,
            array_values(array_intersect($published, $this->eventNamesWithSchema())),
        );
    }

    public function test_schedule_due_of_a_plain_rule_matches_the_contract(): void
    {
        $this->createRule();

        $envelopes = $this->publishes(function (): void {
            $this->materialize();
            // O aviso de 04/04 às 12:00 UTC vence com a passada seguinte: o
            // varrimento roda de 15 em 15 min, e a tolerância de um dia cobre
            // só a queda do processo.
            $this->sweep('2026-04-04 13:00:00');
        });

        $notices = $this->of($envelopes, EventName::SCHEDULE_DUE);

        $this->assertNotSame([], $notices);
        $this->assertValid(EventName::SCHEDULE_DUE, $notices);
    }

    public function test_schedule_due_of_an_escalated_rule_carries_the_offset(): void
    {
        $this->createRule([
            'type' => TriggerConfig::TYPE_ESCALATED,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'custom_offsets' => [-3, 0, 2],
        ]);

        // 15/03 + 30 dias ⇒ vencimento em 14/04; os offsets -3/0/2 disparam em
        // 11/04, 14/04 e 16/04, e cada um é recolhido na passada seguinte.
        $envelopes = $this->publishes(function (): void {
            $this->materialize();
            $this->sweep('2026-04-11 13:00:00', '2026-04-14 13:00:00', '2026-04-16 13:00:00');
        });

        $notices = $this->of($envelopes, EventName::SCHEDULE_DUE);
        $this->assertValid(EventName::SCHEDULE_DUE, $notices);

        // O offset é o que distingue um aviso do outro, e `kind = offset` é o
        // que diz ao consumidor que a casa configurou ESCALATED.
        $kinds = array_column(array_column($notices, 'payload'), 'kind');
        $offsets = array_column(array_column($notices, 'payload'), 'offsets');

        $this->assertContains('offset', $kinds);
        $this->assertContains(0, $offsets);
        $this->assertSame(1, count(array_keys($offsets, -3, true)));
    }

    public function test_schedule_due_of_a_bill_resolves_the_public_subject_id(): void
    {
        $this->billId = $this->withTenantContext($this->tenantId, fn (): string => Bill::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Conta de energia',
            'kind' => Bill::KIND_FIXED,
            'amount' => '210.00',
        ])->id);

        $this->withTenantContext($this->tenantId, function (): void {
            TriggerConfig::create([
                'tenant_id' => $this->tenantId,
                'subject_type' => TriggerConfig::SUBJECT_BILL,
                'bill_id' => $this->billId,
                'title' => 'Conta de energia',
                'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
                'day_of_month' => 10,
                'advance_notice_days' => 3,
            ]);
        });

        $envelopes = $this->publishes(function (): void {
            $this->materialize();
            // Dia 10 com 3 dias de antecedência: o aviso vence em 07/04.
            $this->sweep('2026-04-07 13:00:00');
        });

        $notices = $this->of($envelopes, EventName::SCHEDULE_DUE);
        $this->assertNotSame([], $notices);
        $this->assertValid(EventName::SCHEDULE_DUE, $notices);

        // `asset_id`/`bill_id` são coluna interna; o contrato publica o par
        // genérico, e é ele que o Financial consome.
        $payloads = array_column($notices, 'payload');

        $this->assertSame([TriggerConfig::SUBJECT_BILL], array_values(array_unique(array_column($payloads, 'subject_type'))));
        $this->assertSame([$this->billId], array_values(array_unique(array_column($payloads, 'subject_id'))));
    }

    public function test_occurrence_created_matches_the_contract(): void
    {
        $this->createRule();

        $envelopes = $this->publishes(fn () => $this->materialize());

        $this->assertValid(EventName::OCCURRENCE_CREATED, $this->of($envelopes, EventName::OCCURRENCE_CREATED));
        $this->assertNotSame([], $this->of($envelopes, EventName::OCCURRENCE_CREATED));
    }

    public function test_occurrence_completed_carries_the_recalculated_date(): void
    {
        $this->createRule();

        $envelopes = $this->publishes(function (): void {
            $this->materialize(horizonDays: 10);
            $this->withHeaders($this->headers)
                ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/complete')
                ->assertOk();
        });

        $completions = $this->of($envelopes, EventName::OCCURRENCE_COMPLETED);
        $this->assertValid(EventName::OCCURRENCE_COMPLETED, $completions);
        $this->assertCount(1, $completions);

        // O recálculo rodou dentro da fila antes do envelope ser publicado: a
        // data do próximo ciclo é o contrato, não um palpite.
        $this->assertSame('2026-04-04T12:00:00+00:00', $completions[0]['payload']['next_due_at']);
    }

    public function test_a_deactivated_rule_publishes_a_completion_without_next_date(): void
    {
        $this->createRule();

        $envelopes = $this->publishes(function (): void {
            $this->materialize(horizonDays: 10);

            $this->withHeaders($this->headers)
                ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/complete')
                ->assertOk();

            $this->withHeaders($this->headers)
                ->patchJson('/api/v1/scheduling/trigger-configs/'.$this->ruleId(), ['is_active' => false])
                ->assertOk();

            $this->withHeaders($this->headers)
                ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-04-04').'/complete')
                ->assertOk();
        });

        $completions = $this->of($envelopes, EventName::OCCURRENCE_COMPLETED);
        $this->assertValid(EventName::OCCURRENCE_COMPLETED, $completions);

        // Regra pausada não ganha data nova, e o contrato diz `null` em vez de
        // a data velha do ciclo anterior.
        $this->assertNull($completions[1]['payload']['next_due_at']);
    }

    // -------------------------------------------------------------- helpers

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
            'interval_value' => 10,
            'interval_unit' => 'days',
            ...$payload,
        ])->assertCreated();
    }

    /**
     * Roda o motor inteiro e devolve os envelopes que foram para a fila.
     *
     * @return list<array<string, mixed>>
     */
    private function publishes(callable $flow): array
    {
        Queue::fake([PublishEvent::class]);

        $flow();

        return Queue::pushed(PublishEvent::class)
            ->map(fn (PublishEvent $job): array => $job->envelope)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $envelopes
     * @return list<array<string, mixed>>
     */
    private function of(array $envelopes, string $event): array
    {
        return array_values(array_filter(
            $envelopes,
            fn (array $envelope): bool => $envelope['event'] === $event,
        ));
    }

    private function materialize(int $horizonDays = 45): void
    {
        $this->artisan('scheduling:materialize', ['--days' => $horizonDays])->assertSuccessful();
    }

    /** Uma passada do varrimento por instante, na ordem em que Happens. */
    private function sweep(string ...$instantsUtc): void
    {
        foreach ($instantsUtc as $instantUtc) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($instantUtc, 'UTC'));

            $this->artisan('scheduling:publish-due')->assertSuccessful();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $envelopes
     */
    private function assertValid(string $event, array $envelopes): void
    {
        $schema = json_decode((string) file_get_contents($this->schemaPath($event)));

        foreach ($envelopes as $envelope) {
            // O `opis` separa objeto de lista pelo tipo do dado, e um array
            // associativo do PHP é indistinguível de uma lista: o envelope vai
            // como objeto, que é o que cruza a fila de verdade.
            $result = $this->validator->validate(json_decode((string) json_encode($envelope)), $schema);

            $this->assertTrue(
                $result->isValid(),
                sprintf(
                    "payload de `%s` fora do contrato: %s\n%s",
                    $event,
                    (string) $result->error(),
                    json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ),
            );
        }
    }

    /** @return list<string> */
    private function eventNamesWithSchema(): array
    {
        $names = array_map(
            fn (string $file): string => basename($file, '.schema.json'),
            glob($this->contractsDir().'/*.schema.json') ?: [],
        );

        sort($names);

        return $names;
    }

    private function schemaPath(string $event): string
    {
        return $this->contractsDir().'/'.$event.'.schema.json';
    }

    /** `packages/` é irmão de `api/` no repositório e está montado em `/var/www/packages`. */
    private function contractsDir(): string
    {
        return dirname(__DIR__, 3).self::CONTRACTS;
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
}
