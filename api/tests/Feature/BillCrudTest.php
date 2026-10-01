<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Mordomus\Financial\Models\Bill;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Contrato HTTP das contas: cadastro, cadência delegada ao Scheduling,
 * filtro da lista e escrita restrita a `bills.manage`.
 */
class BillCrudTest extends FeatureTestCase
{
    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('contas@mordomus.test', 'Contas', 'Casa Contas');
        $this->tenantId = $auth['active_tenant'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_owner_creates_a_fixed_bill_with_a_monthly_cadence(): void
    {
        $this->createBill([
            'name' => 'Aluguel',
            'kind' => Bill::KIND_FIXED,
            'category' => 'moradia',
            'amount' => '1800',
            'due_day' => 10,
            'advance_notice_days' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Aluguel')
            ->assertJsonPath('data.kind', Bill::KIND_FIXED)
            ->assertJsonPath('data.category', 'moradia')
            ->assertJsonPath('data.amount', '1800.00')
            ->assertJsonPath('data.currency', 'BRL')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.schedule.type', TriggerConfig::TYPE_CALENDAR_MONTHLY)
            ->assertJsonPath('data.schedule.day_of_month', 10)
            ->assertJsonPath('data.schedule.advance_notice_days', 3)
            ->assertJsonPath('data.schedule.is_active', true);

        // A regra é do Scheduling e R2 vale igual: a primeira data é calculada,
        // não pedida.
        $this->assertSame(TriggerConfig::SUBJECT_BILL, $this->rule()?->subject_type);
        $this->assertSame(10, $this->rule()?->day_of_month);
        $this->assertNotNull($this->rule()?->next_due_at);
    }

    public function test_a_variable_bill_without_cadence_has_no_schedule(): void
    {
        $this->createBill(['name' => 'Gás', 'kind' => Bill::KIND_VARIABLE])
            ->assertCreated()
            ->assertJsonPath('data.schedule', null)
            ->assertJsonPath('data.amount', null);

        $this->assertNull($this->rule());
    }

    public function test_the_bill_name_is_the_title_of_the_rule(): void
    {
        // É o título que aparece na agenda do Scheduling: a conta e a regra
        // precisam ser reconhecidas pelo mesmo nome.
        $this->createBill(['name' => 'Internet fibra', 'due_day' => 15]);

        $this->assertSame('Internet fibra', $this->rule()?->title);
    }

    public function test_owner_lists_and_shows_the_bills(): void
    {
        $aluguel = $this->createBill(['name' => 'Aluguel', 'due_day' => 10])->json('data.id');
        $this->createBill(['name' => 'Gás', 'kind' => Bill::KIND_VARIABLE]);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/bills')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Aluguel')
            ->assertJsonPath('data.1.name', 'Gás');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/bills/'.$aluguel)
            ->assertOk()
            ->assertJsonPath('data.id', $aluguel)
            ->assertJsonPath('data.schedule.day_of_month', 10);
    }

    public function test_the_list_filters_by_kind_and_state(): void
    {
        $this->createBill(['name' => 'Aluguel', 'due_day' => 10]);
        $this->createBill(['name' => 'Gás', 'kind' => Bill::KIND_VARIABLE]);
        $this->createBill(['name' => 'Netflix', 'kind' => Bill::KIND_FIXED, 'is_active' => false]);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/bills?kind=variable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Gás');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/bills?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Netflix');
    }

    public function test_patch_changes_only_what_it_carries(): void
    {
        $billId = $this->createBill([
            'name' => 'Conta de luz',
            'amount' => '150.50',
            'due_day' => 10,
            'advance_notice_days' => 3,
        ])->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/financial/bills/'.$billId, ['amount' => '187.43'])
            ->assertOk()
            ->assertJsonPath('data.amount', '187.43')
            ->assertJsonPath('data.name', 'Conta de luz')
            // Sem `due_day` no payload, a cadência não é reescrita.
            ->assertJsonPath('data.schedule.day_of_month', 10)
            ->assertJsonPath('data.schedule.advance_notice_days', 3);
    }

    public function test_patch_of_the_due_day_moves_the_cadence(): void
    {
        $billId = $this->createBill(['name' => 'Aluguel', 'due_day' => 10])->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/financial/bills/'.$billId, ['due_day' => 20, 'advance_notice_days' => 5])
            ->assertOk()
            ->assertJsonPath('data.schedule.day_of_month', 20)
            ->assertJsonPath('data.schedule.advance_notice_days', 5);

        $this->assertSame(20, $this->rule()?->day_of_month);
    }

    public function test_renaming_the_bill_does_not_duplicate_the_cadence(): void
    {
        // O título da regra é o nome da conta. Trocar o nome faria a regra nova
        // nascer com título novo, e sem pausar a antiga a casa passaria a ter
        // dois vencimentos de aluguel por mês.
        $billId = $this->createBill(['name' => 'Aluguel', 'due_day' => 10])->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/financial/bills/'.$billId, ['name' => 'Aluguel do imóvel', 'due_day' => 10])
            ->assertOk()
            ->assertJsonPath('data.name', 'Aluguel do imóvel')
            ->assertJsonPath('data.schedule.is_active', true);

        // Uma regra só, reaproveitada: a agenda passa a mostrar o nome novo
        // sem que a casa passe a ter dois vencimentos de aluguel por mês.
        $this->assertSame(1, $this->rules()->count());
        $this->assertSame('Aluguel do imóvel', $this->rule()?->title);
    }

    public function test_removing_the_due_day_pauses_the_cadence_without_touching_the_history(): void
    {
        $billId = $this->createBill(['name' => 'Aluguel', 'due_day' => 10])->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/financial/bills/'.$billId, ['due_day' => null])
            ->assertOk()
            ->assertJsonPath('data.schedule.is_active', false);

        $this->assertFalse((bool) $this->rule()?->is_active);
        $this->assertSame(1, $this->rules()->count());
    }

    public function test_deactivating_the_bill_drops_only_what_has_not_due_yet(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-15 12:00:00', 'UTC'));

        $billId = $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00', 'due_day' => 10])->json('data.id');

        // Vencimento de abril lançado à mão (a materialização nunca puxa data
        // passada) e o de maio, que o motor materializa da cadência.
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/occurrences', [
                'bill_id' => $billId,
                'due_date' => '2026-04-10',
                'amount' => 1800,
            ])
            ->assertCreated();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->assertSame(['2026-04-10', '2026-05-10'], $this->dueDates());

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/financial/bills/'.$billId, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.schedule.is_active', false);

        // O que já venceu continua sendo dívida; o que nem venceu deixou de
        // existir sem apagar a linha.
        $this->assertSame(
            [BillOccurrence::STATUS_OPEN, BillOccurrence::STATUS_CANCELLED],
            $this->occurrenceStatuses(),
        );
    }

    public function test_a_bill_of_another_home_is_not_found(): void
    {
        $outra = $this->registerUser('outra-casa@mordomus.test', 'Outra', 'Casa B');

        $foreignBill = $this->withTenantContext($outra['active_tenant'], fn (): string => Bill::create([
            'tenant_id' => $outra['active_tenant'],
            'name' => 'Aluguel da Casa B',
            'kind' => Bill::KIND_FIXED,
        ])->id);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/bills/'.$foreignBill)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'bill_not_found');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/financial/bills/'.$foreignBill, ['amount' => '10.00'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'bill_not_found');
    }

    public function test_member_reads_but_does_not_write(): void
    {
        $billId = $this->createBill(['name' => 'Aluguel', 'amount' => '1800.00'])->json('data.id');
        $member = User::factory()->create();

        Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $this->asUser($member, $this->tenantId)
            ->getJson('/api/v1/financial/bills')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asUser($member, $this->tenantId)
            ->postJson('/api/v1/financial/bills', ['name' => 'Conta de morador', 'kind' => Bill::KIND_FIXED])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required', 'bills.manage');

        $this->asUser($member, $this->tenantId)
            ->patchJson('/api/v1/financial/bills/'.$billId, ['amount' => '1.00'])
            ->assertForbidden()
            ->assertJsonPath('error.details.required', 'bills.manage');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/bills/'.$billId)
            ->assertOk()
            ->assertJsonPath('data.amount', '1800.00');
    }

    public function test_writes_require_a_token(): void
    {
        $this->postJson('/api/v1/financial/bills', ['name' => 'Aluguel', 'kind' => Bill::KIND_FIXED])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_the_payload_is_validated(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/bills', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['name', 'kind']]]);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/bills', ['name' => 'Aluguel', 'kind' => 'mensal'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['kind']]]);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/bills', [
                'name' => 'Aluguel',
                'kind' => Bill::KIND_FIXED,
                'amount' => 'dezoito',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['amount']]]);

        // Dia 32 não existe: o dia do vencimento é o mesmo que o Scheduling
        // valida na regra.
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/bills', [
                'name' => 'Aluguel',
                'kind' => Bill::KIND_FIXED,
                'due_day' => 32,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['due_day']]]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createBill(array $payload): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson(
            '/api/v1/financial/bills',
            $payload + ['kind' => Bill::KIND_FIXED],
        );
    }

    /** @return Collection<int, TriggerConfig> */
    private function rules(): Collection
    {
        return $this->withTenantContext($this->tenantId, fn (): Collection => TriggerConfig::query()->get());
    }

    private function rule(): ?TriggerConfig
    {
        return $this->rules()->first();
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
