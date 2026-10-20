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

class PublishedEventContractTest extends FeatureTestCase
{
    private const CONTRACTS = '/packages/contracts';

    private string $tenantId;

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

        $envelopes = $this->publishes(function (): void {
            $this->materialize();
            $this->sweep('2026-04-11 13:00:00', '2026-04-14 13:00:00', '2026-04-16 13:00:00');
        });

        $notices = $this->of($envelopes, EventName::SCHEDULE_DUE);
        $this->assertValid(EventName::SCHEDULE_DUE, $notices);

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

            $this->sweep('2026-04-07 13:00:00');
        });

        $notices = $this->of($envelopes, EventName::SCHEDULE_DUE);
        $this->assertNotSame([], $notices);
        $this->assertValid(EventName::SCHEDULE_DUE, $notices);

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

        $this->assertNull($completions[1]['payload']['next_due_at']);
    }

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

    private function publishes(callable $flow): array
    {
        Queue::fake([PublishEvent::class]);

        $flow();

        return Queue::pushed(PublishEvent::class)
            ->map(fn (PublishEvent $job): array => $job->envelope)
            ->values()
            ->all();
    }

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

    private function sweep(string ...$instantsUtc): void
    {
        foreach ($instantsUtc as $instantUtc) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($instantUtc, 'UTC'));

            $this->artisan('scheduling:publish-due')->assertSuccessful();
        }
    }

    private function assertValid(string $event, array $envelopes): void
    {
        $schema = json_decode((string) file_get_contents($this->schemaPath($event)));

        foreach ($envelopes as $envelope) {

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
