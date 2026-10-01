<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mordomus\Financial\Events\EventName;
use Mordomus\Financial\Models\Bill;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\PaymentRecord;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Scheduling\Events\EventName as ScheduleEventName;
use Mordomus\Scheduling\Jobs\PublishEvent;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Ciclo do vencimento: o que o motor materializa vira linha na conta, a baixa
 * é idempotente e o histórico é filtrável.
 *
 * O relógio é congelado porque o que está em jogo aqui é a fronteira com o
 * Scheduling: o vencimento nasce na data que o motor calculou, e não na que o
 * Financial calcularia.
 */
class BillOccurrenceFlowTest extends FeatureTestCase
{
    private const NOW = '2026-04-01 12:00:00';

    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    private string $ownerId = '';

    private string $billId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('vencimentos@mordomus.test', 'Vencimentos', 'Casa Vencimentos');
        $this->tenantId = $auth['active_tenant'];
        $this->ownerId = (string) $auth['user']['id'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        // O token é emitido antes do congelamento: o `firebase/php-jwt` valida
        // `iat`/`nbf` contra o relógio do sistema, e um token emitido no
        // "futuro" do relógio congelado seria recusado como inválido.
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_materialized_occurrence_becomes_a_due_date_of_the_bill(): void
    {
        $this->createBill(['name' => 'Conta de luz', 'amount' => '187.43', 'due_day' => 10, 'advance_notice_days' => 3]);

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/occurrences')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // Dia 10 com a janela padrão de 45 dias: abril e maio.
            ->assertJsonPath('data.0.due_date', '2026-04-10')
            ->assertJsonPath('data.0.amount', '187.43')
            ->assertJsonPath('data.0.status', BillOccurrence::STATUS_OPEN)
            ->assertJsonPath('data.0.bill_name', 'Conta de luz')
            ->assertJsonPath('data.0.schedule_id', $this->scheduleId('2026-04-10'))
            ->assertJsonPath('data.0.payments', [])
            ->assertJsonPath('data.1.due_date', '2026-05-10');
    }

    public function test_the_due_date_is_the_date_the_engine_calculated(): void
    {
        $this->createBill(['name' => 'Aluguel', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        // R7: a data do vencimento é a `scheduled_for` da ocorrência do motor,
        // e o Financial só a grava.
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/occurrences')
            ->assertOk()
            ->assertJsonPath('data.0.due_date', $this->scheduleDate('2026-04-10'));

        $this->assertSame(
            $this->scheduleId('2026-04-10'),
            $this->withTenantContext(
                $this->tenantId,
                fn (): string => (string) BillOccurrence::query()->where('due_date', '2026-04-10')->value('schedule_id'),
            ),
        );
    }

    public function test_a_repeated_materialization_does_not_duplicate_the_due_date(): void
    {
        $this->createBill(['name' => 'Aluguel', 'due_day' => 10]);

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $this->artisan('scheduling:materialize')->assertSuccessful();
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->assertSame(2, $this->occurrenceCount());
        $this->assertSame(['2026-04-10', '2026-05-10'], $this->dueDates());
    }

    public function test_the_same_event_reconciled_twice_keeps_one_line(): void
    {
        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $occurrenceId = $this->occurrenceId('2026-04-10');

        // Reentrega do mesmo `schedule.occurrence.created`: a linha existe, é
        // reconciliada, e o índice único fecha qualquer corrida.
        $this->artisan('scheduling:materialize')->assertSuccessful();
        $this->artisan('scheduling:publish-due')->assertSuccessful();

        $this->assertSame(2, $this->occurrenceCount());
        $this->assertSame($occurrenceId, $this->occurrenceId('2026-04-10'));
    }

    public function test_a_bill_of_day_31_keeps_the_cycle_across_short_months(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-31 12:00:00', 'UTC'));

        $this->createBill(['name' => 'Condomínio', 'due_day' => 31]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        // Fevereiro é curto: o dia 31 vira o último dia do mês, e o ciclo não
        // fica preso nele nem pula para março.
        $this->assertSame(['2026-01-31', '2026-02-28'], $this->dueDates());
    }

    public function test_a_variable_bill_materializes_the_due_date_with_no_amount(): void
    {
        $this->createBill(['name' => 'Gás', 'kind' => Bill::KIND_VARIABLE, 'due_day' => 15]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/occurrences')
            ->assertOk()
            ->assertJsonPath('data.0.due_date', '2026-04-15')
            ->assertJsonPath('data.0.amount', '0.00');
    }

    public function test_the_due_date_of_a_variable_bill_is_launched_by_hand(): void
    {
        $this->createBill(['name' => 'Gás', 'kind' => Bill::KIND_VARIABLE]);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/occurrences', [
                'bill_id' => $this->billId,
                'due_date' => '2026-04-18',
                'amount' => '213.77',
            ])
            ->assertCreated()
            ->assertJsonPath('data.due_date', '2026-04-18')
            ->assertJsonPath('data.amount', '213.77')
            ->assertJsonPath('data.status', BillOccurrence::STATUS_OPEN)
            // Lançamento manual não tem agenda por trás dele.
            ->assertJsonPath('data.schedule_id', null);
    }

    public function test_launching_the_same_due_date_twice_is_a_conflict(): void
    {
        $this->createBill(['name' => 'Gás', 'kind' => Bill::KIND_VARIABLE]);
        $this->launchManual('2026-04-18', '213.77')->assertCreated();

        $this->launchManual('2026-04-18', '213.77')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'bill_occurrence_exists')
            ->assertJsonPath('error.details.due_date', '2026-04-18');
    }

    public function test_paying_quits_the_due_date_and_records_the_receipt(): void
    {
        $this->createBill(['name' => 'Conta de luz', 'amount' => '187.43', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->pay($this->occurrenceId('2026-04-10'), ['method' => PaymentRecord::METHOD_PIX])
            ->assertOk()
            ->assertJsonPath('data.status', BillOccurrence::STATUS_PAID)
            ->assertJsonPath('data.amount', '187.43')
            ->assertJsonPath('data.paid_by', $this->ownerId)
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.amount', '187.43')
            ->assertJsonPath('data.payments.0.method', PaymentRecord::METHOD_PIX);
    }

    public function test_paying_twice_does_not_open_a_second_entry(): void
    {
        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $occurrenceId = $this->occurrenceId('2026-04-10');
        $first = $this->pay($occurrenceId, ['method' => PaymentRecord::METHOD_BOLETO])->assertOk();
        $second = $this->pay($occurrenceId, ['method' => PaymentRecord::METHOD_PIX])->assertOk();

        $this->assertSame(1, $this->paymentCount());
        $this->assertSame($first->json('data.paid_at'), $second->json('data.paid_at'));
        $this->assertSame(
            PaymentRecord::METHOD_BOLETO,
            $second->json('data.payments.0.method'),
            'A segunda chamada não substitui a baixa original.',
        );
    }

    public function test_the_paid_amount_wins_over_the_expected_one(): void
    {
        $this->createBill(['name' => 'Gás', 'kind' => Bill::KIND_VARIABLE, 'due_day' => 15]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->pay($this->occurrenceId('2026-04-15'), [
            'method' => PaymentRecord::METHOD_CASH,
            'amount' => '213.77',
        ])
            ->assertOk()
            ->assertJsonPath('data.amount', '213.77')
            ->assertJsonPath('data.payments.0.amount', '213.77');
    }

    public function test_paying_a_cancelled_due_date_is_a_conflict(): void
    {
        $billId = $this->createBill(['name' => 'Netflix', 'amount' => '39.90', 'due_day' => 10])->json('data.id');
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/financial/bills/'.$billId, ['is_active' => false])
            ->assertOk();

        $this->pay($this->occurrenceId('2026-04-10'), ['method' => PaymentRecord::METHOD_CREDIT_CARD])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'bill_occurrence_not_payable')
            ->assertJsonPath('error.details.status', BillOccurrence::STATUS_CANCELLED);

        $this->assertSame(0, $this->paymentCount());
    }

    public function test_paying_a_due_date_of_another_home_is_not_found(): void
    {
        $outra = $this->registerUser('outra-casa-vencimentos@mordomus.test', 'Outra', 'Casa B');

        $foreignBill = $this->withTenantContext($outra['active_tenant'], fn (): string => Bill::create([
            'tenant_id' => $outra['active_tenant'],
            'name' => 'Aluguel da Casa B',
            'kind' => Bill::KIND_FIXED,
        ])->id);

        $foreignOccurrence = $this->withTenantContext($outra['active_tenant'], fn (): string => BillOccurrence::create([
            'tenant_id' => $outra['active_tenant'],
            'bill_id' => $foreignBill,
            'due_date' => '2026-04-10',
            'amount' => '1800.00',
        ])->id);

        $this->pay($foreignOccurrence, ['method' => PaymentRecord::METHOD_PIX])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'bill_occurrence_not_found');

        $this->assertSame(0, $this->paymentCount());
    }

    public function test_a_member_pays_but_does_not_launch(): void
    {
        $this->createBill(['name' => 'Gás', 'kind' => Bill::KIND_VARIABLE]);
        $occurrence = $this->launchManual('2026-04-18', '213.77')->assertCreated()->json('data.id');
        $member = User::factory()->create();

        Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        // O relógio volta ao real para emitir o token do morador: `exp` sai de
        // `now()`, e um token emitido no congelado já nasceria vencido para o
        // `firebase/php-jwt`, que compara com o relógio do sistema.
        CarbonImmutable::setTestNow();
        $memberHeaders = $this->authHeadersFor($member, $this->tenantId);
        CarbonImmutable::setTestNow(self::NOW);

        $this->withHeaders($memberHeaders)
            ->postJson('/api/v1/financial/occurrences', [
                'bill_id' => $this->billId,
                'due_date' => '2026-04-19',
                'amount' => '10.00',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.details.required', 'bills.manage');

        // Pagar é tarefa de morador: `bills.pay` existe justamente para isso.
        $this->withHeaders($memberHeaders)
            ->postJson('/api/v1/financial/occurrences/'.$occurrence.'/paid', ['method' => PaymentRecord::METHOD_PIX])
            ->assertOk()
            ->assertJsonPath('data.status', BillOccurrence::STATUS_PAID)
            ->assertJsonPath('data.paid_by', $member->id);
    }

    public function test_paying_without_a_token_is_unauthorized(): void
    {
        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->flushHeaders()
            ->postJson('/api/v1/financial/occurrences/'.$this->occurrenceId('2026-04-10').'/paid', [
                'method' => PaymentRecord::METHOD_PIX,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_the_history_filters_by_month_and_status(): void
    {
        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();
        $this->pay($this->occurrenceId('2026-04-10'), ['method' => PaymentRecord::METHOD_PIX])->assertOk();

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/occurrences?month=2026-04')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.due_date', '2026-04-10')
            ->assertJsonPath('data.0.status', BillOccurrence::STATUS_PAID);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/occurrences?month=2026-05')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', BillOccurrence::STATUS_OPEN);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/occurrences?status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', BillOccurrence::STATUS_PAID);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/occurrences?bill_id='.$this->billId)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_history_of_another_home_is_empty(): void
    {
        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        // A outra casa é criada com o relógio real pelo mesmo motivo do
        // token do morador: `exp` sai de `now()` e nasceria vencido no
        // congelado.
        CarbonImmutable::setTestNow();
        $outra = $this->registerUser('outra-casa-historico@mordomus.test', 'Outra', 'Casa B');
        CarbonImmutable::setTestNow(self::NOW);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$outra['access_token'],
            'Accept' => 'application/json',
        ])
            ->getJson('/api/v1/financial/occurrences')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->assertSame(2, $this->occurrenceCount());
    }

    public function test_month_and_range_are_not_combined(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/occurrences?month=2026-04&from=2026-04-01')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['month']]]);
    }

    public function test_the_overdue_projection_marks_only_what_has_gone_by(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-12 12:00:00', 'UTC'));

        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();
        $this->launchManual('2026-04-02', '1800.00')->assertCreated();

        $this->artisan('financial:project')->assertSuccessful();

        // Em 12/04 o dia 10 de abril já passou e o motor só materializou o de
        // maio: o vencido é o lançamento de 02/04, e ele muda de estado.
        $this->assertSame(
            [BillOccurrence::STATUS_OVERDUE, BillOccurrence::STATUS_OPEN],
            $this->occurrenceStatuses(),
        );

        // Repetir a projeção não muda nada: só `open` que já venceu entra.
        $this->artisan('financial:project')->assertSuccessful();

        $this->assertSame(
            [BillOccurrence::STATUS_OVERDUE, BillOccurrence::STATUS_OPEN],
            $this->occurrenceStatuses(),
        );
    }

    public function test_the_projection_refills_a_due_date_the_event_never_delivered(): void
    {
        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        // O consumidor caiu depois de a ocorrência do motor existir: o evento
        // já foi publicado e não volta, e o motor considera a data
        // materializada. O vencimento sumiria da lista para sempre sem a
        // projeção.
        $this->withTenantContext($this->tenantId, function (): void {
            BillOccurrence::query()->delete();
        });

        $this->assertSame(0, $this->occurrenceCount());

        $this->artisan('financial:project')->assertSuccessful();

        $this->assertSame(['2026-04-10', '2026-05-10'], $this->dueDates());
        $this->assertSame(
            $this->scheduleId('2026-04-10'),
            $this->withTenantContext(
                $this->tenantId,
                fn (): string => (string) BillOccurrence::query()->whereDate('due_date', '2026-04-10')->value('schedule_id'),
            ),
        );

        // Repetir a projeção não duplica nada.
        $this->artisan('financial:project')->assertSuccessful();

        $this->assertSame(2, $this->occurrenceCount());
    }

    public function test_the_projection_keeps_a_paid_due_date_paid(): void
    {
        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();
        $this->pay($this->occurrenceId('2026-04-10'), ['method' => PaymentRecord::METHOD_PIX, 'amount' => '1780.00'])->assertOk();

        $this->artisan('financial:project')->assertSuccessful();

        // A reconciliação só preenche o que falta: o que foi pago não volta
        // a ser previsto, e a linha não se abre de novo.
        $this->assertSame(
            BillOccurrence::STATUS_PAID,
            $this->withTenantContext(
                $this->tenantId,
                fn (): string => (string) BillOccurrence::query()->whereDate('due_date', '2026-04-10')->value('status'),
            ),
        );
        $this->assertSame('1780.00', $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) BillOccurrence::query()->whereDate('due_date', '2026-04-10')->value('amount'),
        ));
    }

    public function test_an_overdue_due_date_can_still_be_paid(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-12 12:00:00', 'UTC'));

        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->launchManual('2026-04-02', '1800.00')->assertCreated();
        $this->artisan('financial:project')->assertSuccessful();

        $this->pay($this->occurrenceId('2026-04-02'), ['method' => PaymentRecord::METHOD_TRANSFER])
            ->assertOk()
            ->assertJsonPath('data.status', BillOccurrence::STATUS_PAID);
    }

    public function test_the_notice_of_the_bill_republishes_in_the_bill_vocabulary(): void
    {
        Queue::fake([PublishEvent::class]);

        $this->createBill(['name' => 'Conta de luz', 'amount' => '187.43', 'due_day' => 10, 'advance_notice_days' => 3]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        // Aviso com 3 dias de antecedência vence em 07/04 às 09:00 de São
        // Paulo (12:00 UTC).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-07 13:00:00', 'UTC'));
        $this->artisan('scheduling:publish-due')->assertSuccessful();

        $notices = Queue::pushed(PublishEvent::class)
            ->map(fn (PublishEvent $job): array => $job->envelope)
            ->filter(fn (array $envelope): bool => $envelope['event'] === EventName::BILL_DUE)
            ->values();

        $this->assertCount(1, $notices);

        $payload = $notices[0]['payload'];

        $this->assertSame($this->occurrenceId('2026-04-10'), $payload['bill_occurrence_id']);
        $this->assertSame($this->billId, $payload['bill_id']);
        $this->assertSame('187.43', $payload['amount']);
        $this->assertSame('2026-04-10', $payload['due_date']);
        $this->assertSame('advance_notice', $payload['kind']);
        $this->assertNotEmpty($payload['dedupe_key']);
    }

    public function test_the_notice_of_an_asset_publishes_nothing_of_a_bill(): void
    {
        Queue::fake([PublishEvent::class]);

        $roomId = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Sala'])
            ->assertCreated()
            ->json('data.id');

        $assetId = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets', ['room_id' => $roomId, 'name' => 'Ar-condicionado'])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets/'.$assetId.'/schedule', [
                'title' => 'Limpeza do filtro',
                'type' => TriggerConfig::TYPE_INTERVAL,
                'interval_value' => 30,
                'interval_unit' => 'days',
            ])
            ->assertCreated();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        // A ocorrência do ativo vence em 01/05, e o aviso sai no mesmo dia: o
        // consumidor do Financial olha o alvo e não faz nada com ele.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 13:00:00', 'UTC'));
        $this->artisan('scheduling:publish-due')->assertSuccessful();

        Queue::assertPushed(
            PublishEvent::class,
            fn (PublishEvent $job): bool => $job->envelope['event'] === ScheduleEventName::SCHEDULE_DUE,
        );
        Queue::assertNotPushed(
            PublishEvent::class,
            fn (PublishEvent $job): bool => $job->envelope['event'] === EventName::BILL_DUE,
        );

        $this->assertSame(0, $this->occurrenceCount());
    }

    public function test_paying_publishes_bill_paid_once(): void
    {
        Queue::fake([PublishEvent::class]);

        $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10]);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $occurrenceId = $this->occurrenceId('2026-04-10');
        $this->pay($occurrenceId, ['method' => PaymentRecord::METHOD_PIX])->assertOk();
        $this->pay($occurrenceId, ['method' => PaymentRecord::METHOD_PIX])->assertOk();

        $paid = Queue::pushed(PublishEvent::class)
            ->map(fn (PublishEvent $job): array => $job->envelope)
            ->filter(fn (array $envelope): bool => $envelope['event'] === EventName::BILL_PAID)
            ->values();

        $this->assertCount(1, $paid);
        $this->assertSame($occurrenceId, $paid[0]['payload']['bill_occurrence_id']);
        $this->assertSame('1800.00', $paid[0]['payload']['amount']);
        $this->assertSame($this->ownerId, $paid[0]['payload']['paid_by']);
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createBill(array $payload): TestResponse
    {
        $response = $this->withHeaders($this->headers)->postJson(
            '/api/v1/financial/bills',
            $payload + ['kind' => Bill::KIND_FIXED],
        );

        $this->billId = (string) $response->json('data.id');

        return $response;
    }

    private function launchManual(string $dueDate, string $amount): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson('/api/v1/financial/occurrences', [
            'bill_id' => $this->billId,
            'due_date' => $dueDate,
            'amount' => $amount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pay(string $occurrenceId, array $payload): TestResponse
    {
        return $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/occurrences/'.$occurrenceId.'/paid', $payload);
    }

    private function occurrenceCount(): int
    {
        return $this->withTenantContext($this->tenantId, fn (): int => BillOccurrence::query()->count());
    }

    private function paymentCount(): int
    {
        return $this->withTenantContext($this->tenantId, fn (): int => PaymentRecord::query()->count());
    }

    private function occurrenceId(string $dueDate): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) BillOccurrence::query()->where('due_date', $dueDate)->value('id'),
        );
    }

    private function scheduleId(string $dueDate): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) BillOccurrence::query()->whereDate('due_date', $dueDate)->value('schedule_id'),
        );
    }

    private function scheduleDate(string $dueDate): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) JobSchedule::query()
                ->where('scheduled_for', $dueDate)
                ->value('scheduled_for')
                ?->format('Y-m-d'),
        );
    }

    /** @return list<string> */
    private function dueDates(): array
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): array => BillOccurrence::query()
                ->orderBy('due_date')
                ->pluck('due_date')
                ->map(fn ($dueDate): string => CarbonImmutable::parse($dueDate)->format('Y-m-d'))
                ->all(),
        );
    }

    /** @return list<string> */
    private function occurrenceStatuses(): array
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): array => BillOccurrence::query()->orderBy('due_date')->pluck('status')->all(),
        );
    }
}
