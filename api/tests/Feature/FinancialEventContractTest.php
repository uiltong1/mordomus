<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Mordomus\Financial\Events\EventName;
use Mordomus\Financial\Models\Bill;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\PaymentRecord;
use Mordomus\Financial\Models\SplitEntry;
use Mordomus\Financial\Models\SplitRule;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Scheduling\Jobs\PublishEvent;
use Opis\JsonSchema\Validator;

/**
 * Contrato dos eventos publicados pelo Financial (ADR-009).
 *
 * O schema é a fonte da verdade do payload: o teste deixa o módulo inteiro
 * rodar — motor materializando, varrimento de avisos e baixa de pagamento — e
 * valida contra `packages/contracts` o que de fato foi para a fila. Campo
 * renomeado ou `dedupe_key` ausente quebram o build antes de chegarem ao
 * Notification.
 *
 * Só o `PublishEvent` é falsado: o materializador precisa rodar de verdade
 * para que haja o que publicar.
 */
class FinancialEventContractTest extends FeatureTestCase
{
    private const CONTRACTS = '/packages/contracts';

    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    private Validator $validator;

    private string $ownerId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('contrato-financas@mordomus.test', 'Contrato', 'Casa Contrato');
        $this->tenantId = $auth['active_tenant'];
        $this->ownerId = (string) $auth['user']['id'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        $this->validator = new Validator;

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_every_financial_event_has_a_schema(): void
    {
        $published = [EventName::BILL_DUE, EventName::BILL_PAID, EventName::EXPENSE_SPLIT_COMPUTED];

        $this->assertSame(
            $published,
            array_values(array_intersect($published, $this->eventNamesWithSchema())),
        );
    }

    public function test_bill_due_matches_the_contract(): void
    {
        $billId = $this->createBill();

        $notices = $this->publishes(function (): void {
            $this->artisan('scheduling:materialize')->assertSuccessful();

            // Dia 10 com 3 dias de antecedência: o aviso vence em 07/04.
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-07 13:00:00', 'UTC'));
            $this->artisan('scheduling:publish-due')->assertSuccessful();
        });

        $this->assertNotSame([], $notices);
        $this->assertValid(EventName::BILL_DUE, $notices);

        $payload = $notices[0]['payload'];

        $this->assertSame($this->tenantId, $payload['tenant_id']);
        $this->assertSame($billId, $payload['bill_id']);
        $this->assertSame('187.43', $payload['amount']);
        $this->assertSame('2026-04-10', $payload['due_date']);
    }

    public function test_bill_paid_matches_the_contract(): void
    {
        $billId = $this->createBill();

        $paid = $this->publishes(function (): void {
            $this->artisan('scheduling:materialize')->assertSuccessful();

            $occurrenceId = $this->withTenantContext(
                $this->tenantId,
                fn (): string => (string) BillOccurrence::query()->whereDate('due_date', '2026-04-10')->value('id'),
            );

            $this->withHeaders($this->headers)
                ->postJson('/api/v1/financial/occurrences/'.$occurrenceId.'/paid', [
                    'method' => PaymentRecord::METHOD_PIX,
                    'amount' => '190.00',
                ])
                ->assertOk();
        });

        $this->assertCount(1, $paid);
        $this->assertValid(EventName::BILL_PAID, $paid);

        $payload = $paid[0]['payload'];

        $this->assertSame($billId, $payload['bill_id']);
        $this->assertSame('190.00', $payload['amount']);
        $this->assertSame(PaymentRecord::METHOD_PIX, $payload['method']);
        $this->assertSame('2026-04-10', $payload['due_date']);
    }

    /**
     * As cotas do vencimento saem no `expense.split_computed`, e a soma delas
     * é o valor da conta (R5). A validação é do envelope que foi para a fila,
     * com o módulo inteiro rodando.
     */
    public function test_expense_split_computed_matches_the_contract(): void
    {
        $this->createSplitRule([$this->ownerId, $this->addResident('bruno@mordomus.test', 'Bruno')]);

        $billId = $this->createBill();

        $splits = $this->publishes(function (): void {
            $this->artisan('scheduling:materialize')->assertSuccessful();
        });

        $this->assertNotSame([], $splits);
        $this->assertValid(EventName::EXPENSE_SPLIT_COMPUTED, $splits);

        $payload = $splits[0]['payload'];

        $this->assertSame($this->tenantId, $payload['tenant_id']);
        $this->assertSame($billId, $payload['bill_id']);
        $this->assertSame('187.43', $payload['amount']);
        $this->assertSame('2026-04-10', $payload['due_date']);
        $this->assertSame('EQUAL', $payload['mode']);
        $this->assertNotEmpty($payload['dedupe_key']);
    }

    public function test_a_manual_launch_publishes_no_event(): void
    {
        $billId = $this->createBill();

        // O lançamento manual é escrita do morador, não do motor: nada é
        // publicado, e o Notification não recebe aviso de algo que ele mesmo
        // acabou de cadastrar.
        $events = $this->publishes(function () use ($billId): void {
            $this->withHeaders($this->headers)
                ->postJson('/api/v1/financial/occurrences', [
                    'bill_id' => $billId,
                    'due_date' => '2026-04-18',
                    'amount' => '55.00',
                ])
                ->assertCreated();
        });

        $this->assertSame([], $events);
    }

    // -------------------------------------------------------------- helpers

    /**
     * Regra padrão da casa em `EQUAL`, com os moradores que a casa tem.
     *
     * @param  list<string>  $userIds
     */
    private function createSplitRule(array $userIds): void
    {
        $ruleId = $this->withTenantContext($this->tenantId, fn (): string => (string) SplitRule::create([
            'tenant_id' => $this->tenantId,
            'bill_id' => null,
            'mode' => SplitRule::MODE_EQUAL,
            'is_active' => true,
        ])->id);

        foreach ($userIds as $userId) {
            $this->withTenantContext($this->tenantId, fn () => SplitEntry::create([
                'split_rule_id' => $ruleId,
                'user_id' => $userId,
            ]));
        }
    }

    private function addResident(string $email, string $name): string
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);

        $this->withTenantContext($this->tenantId, fn () => Membership::create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]));

        return (string) $user->id;
    }

    private function createBill(): string
    {
        return $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/bills', [
                'name' => 'Conta de luz',
                'kind' => Bill::KIND_FIXED,
                'category' => 'energia',
                'amount' => '187.43',
                'due_day' => 10,
                'advance_notice_days' => 3,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Roda o módulo inteiro e devolve os envelopes do Financial que foram para
     * a fila.
     *
     * @return list<array<string, mixed>>
     */
    private function publishes(callable $flow): array
    {
        Queue::fake([PublishEvent::class]);

        $flow();

        return Queue::pushed(PublishEvent::class)
            ->map(fn (PublishEvent $job): array => $job->envelope)
            ->filter(fn (array $envelope): bool => in_array($envelope['event'], $this->contractedEvents(), true))
            ->values()
            ->all();
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

    /**
     * Eventos do Financial que têm contrato em `packages/contracts`.
     *
     * @return list<string>
     */
    private function contractedEvents(): array
    {
        return [EventName::BILL_DUE, EventName::BILL_PAID, EventName::EXPENSE_SPLIT_COMPUTED];
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
}
