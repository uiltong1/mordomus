<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Mordomus\Financial\Models\Bill;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\PaymentRecord;

class BillSummaryTest extends FeatureTestCase
{
    private const NOW = '2026-04-01 12:00:00';

    private string $tenantId;

    private array $headers;

    private string $billId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('resumo@mordomus.test', 'Resumo', 'Casa Resumo');
        $this->tenantId = $auth['active_tenant'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_an_empty_month_closes_with_zero_in_every_bucket(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/summary?month=2026-03')
            ->assertOk()
            ->assertJsonPath('data.month', '2026-03')
            ->assertJsonPath('data.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('data.totals.due', '0.00')
            ->assertJsonPath('data.totals.paid', '0.00')
            ->assertJsonPath('data.counts.occurrences', 0)
            ->assertJsonPath('data.by_status', [
                BillOccurrence::STATUS_OPEN => 0,
                BillOccurrence::STATUS_PAID => 0,
                BillOccurrence::STATUS_OVERDUE => 0,
                BillOccurrence::STATUS_CANCELLED => 0,
            ])
            ->assertJsonPath('data.by_category', []);
    }

    public function test_the_month_totals_add_up_in_cents(): void
    {
        $this->createBill('Aluguel', 'moradia', '1800.00', dueDay: 10);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->payOn('2026-04-10', ['method' => PaymentRecord::METHOD_TRANSFER])->assertOk();
        $this->launchFor($this->billId, '2026-04-18', '213.77')->assertCreated();

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/summary?month=2026-04')
            ->assertOk()

            ->assertJsonPath('data.totals.due', '2013.77')
            ->assertJsonPath('data.totals.paid', '1800.00')
            ->assertJsonPath('data.totals.open', '213.77')
            ->assertJsonPath('data.totals.overdue', '0.00')
            ->assertJsonPath('data.counts.occurrences', 2)
            ->assertJsonPath('data.counts.paid', 1)
            ->assertJsonPath('data.counts.open', 1);
    }

    public function test_the_summary_only_covers_the_requested_month(): void
    {
        $this->createBill('Aluguel', 'moradia', '1800.00', dueDay: 10);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/summary?month=2026-04')
            ->assertOk()
            ->assertJsonPath('data.counts.occurrences', 1)
            ->assertJsonPath('data.totals.due', '1800.00');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/summary?month=2026-05')
            ->assertOk()
            ->assertJsonPath('data.counts.occurrences', 1)
            ->assertJsonPath('data.totals.due', '1800.00');
    }

    public function test_the_summary_groups_by_category_of_the_bill(): void
    {
        $aluguel = $this->createBill('Aluguel', 'moradia', '1200.00')->json('data.id');
        $luz = $this->createBill('Luz', 'energia', '187.43')->json('data.id');
        $gas = $this->createBill('Gás', null, null, Bill::KIND_VARIABLE)->json('data.id');

        $this->launchFor($aluguel, '2026-04-10', '1200.00');
        $this->launchFor($luz, '2026-04-15', '187.43');
        $this->launchFor($gas, '2026-04-20', '95.10');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/summary?month=2026-04')
            ->assertOk()
            ->assertJsonCount(3, 'data.by_category')

            ->assertJsonPath('data.by_category.0.category', 'energia')
            ->assertJsonPath('data.by_category.0.due', '187.43')
            ->assertJsonPath('data.by_category.0.open', '187.43')
            ->assertJsonPath('data.by_category.1.category', 'moradia')
            ->assertJsonPath('data.by_category.1.due', '1200.00')
            ->assertJsonPath('data.by_category.1.occurrences', 1)
            ->assertJsonPath('data.by_category.2.category', null)
            ->assertJsonPath('data.by_category.2.due', '95.10');
    }

    public function test_the_cancelled_amount_leaves_the_open_totals_but_stays_in_due(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-15 12:00:00', 'UTC'));

        $billId = $this->createBill('Netflix', 'lazer', '39.90')->json('data.id');
        $this->launchFor($billId, '2026-04-10', '39.90');
        $this->launchFor($billId, '2026-04-20', '39.90');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/financial/bills/'.$billId, ['is_active' => false])
            ->assertOk();

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/summary?month=2026-04')
            ->assertOk()

            ->assertJsonPath('data.totals.due', '79.80')
            ->assertJsonPath('data.totals.open', '39.90')
            ->assertJsonPath('data.totals.cancelled', '39.90')
            ->assertJsonPath('data.counts.occurrences', 2)
            ->assertJsonPath('data.counts.cancelled', 1);
    }

    public function test_the_current_month_is_the_default(): void
    {
        $this->createBill('Aluguel', 'moradia', '1800.00', dueDay: 10);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/summary')
            ->assertOk()
            ->assertJsonPath('data.month', '2026-04')
            ->assertJsonPath('data.totals.due', '1800.00');
    }

    public function test_the_summary_of_another_home_is_empty(): void
    {
        $this->createBill('Aluguel', 'moradia', '1800.00', dueDay: 10);
        $this->artisan('scheduling:materialize')->assertSuccessful();

        CarbonImmutable::setTestNow();
        $outra = $this->registerUser('outra-casa-resumo@mordomus.test', 'Outra', 'Casa B');
        CarbonImmutable::setTestNow(self::NOW);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$outra['access_token'],
            'Accept' => 'application/json',
        ])
            ->getJson('/api/v1/financial/summary?month=2026-04')
            ->assertOk()
            ->assertJsonPath('data.totals.due', '0.00')
            ->assertJsonPath('data.counts.occurrences', 0);
    }

    public function test_an_invalid_month_is_rejected(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/summary?month=abril')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['month']]]);
    }

    public function test_the_summary_needs_a_token(): void
    {
        $this->withHeaders($this->headers)->getJson('/api/v1/financial/summary')->assertOk();
        $this->flushHeaders()->getJson('/api/v1/financial/summary')->assertUnauthorized();
    }

    private function createBill(
        string $name,
        ?string $category,
        ?string $amount,
        string $kind = Bill::KIND_FIXED,
        ?int $dueDay = null,
    ): TestResponse {
        $response = $this->withHeaders($this->headers)->postJson('/api/v1/financial/bills', [
            'name' => $name,
            'kind' => $kind,
            'category' => $category,
            'amount' => $amount,
            'due_day' => $dueDay,
        ]);

        $this->billId = (string) $response->json('data.id');

        return $response;
    }

    private function launchFor(string $billId, string $dueDate, string $amount): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson('/api/v1/financial/occurrences', [
            'bill_id' => $billId,
            'due_date' => $dueDate,
            'amount' => $amount,
        ]);
    }

    private function payOn(string $dueDate, array $payload): TestResponse
    {
        $occurrenceId = $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) BillOccurrence::query()->whereDate('due_date', $dueDate)->value('id'),
        );

        return $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/occurrences/'.$occurrenceId.'/paid', $payload);
    }
}
