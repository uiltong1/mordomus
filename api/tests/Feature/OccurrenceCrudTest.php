<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Mordomus\Financial\Models\Bill;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Permission;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\CapabilityResolverService;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Contrato HTTP das ocorrências e do atalho do Maintenance.
 *
 * O que importa aqui é o que a API **não** deixa fazer: vazar ocorrência de
 * outra residência, concluir sem a capability, e devolver `next_due_at` que
 * ainda não existe.
 */
class OccurrenceCrudTest extends FeatureTestCase
{
    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('agenda@mordomus.test', 'Agenda', 'Casa Agenda');
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

        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => TriggerConfig::SUBJECT_ASSET,
            'subject_id' => $this->assetId,
            'title' => 'Limpeza do filtro',
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 10,
            'interval_unit' => 'days',
        ])->assertCreated();

        // O relógio é congelado só depois do token: o `firebase/php-jwt` valida
        // `iat`/`nbf` contra o relógio do sistema, e um token emitido no
        // "futuro" do relógio congelado seria recusado como inválido.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

        $this->artisan('scheduling:materialize')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_lists_the_agenda_with_the_target_resolved(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/occurrences')
            ->assertOk()
            ->assertJsonPath('data.0.scheduled_for', '2026-03-25')
            ->assertJsonPath('data.0.due_at', '2026-03-25T09:00:00-03:00')
            ->assertJsonPath('data.0.status', JobSchedule::STATUS_PENDING)
            ->assertJsonPath('data.0.subject_type', TriggerConfig::SUBJECT_ASSET)
            ->assertJsonPath('data.0.subject_id', $this->assetId)
            ->assertJsonPath('data.0.title', 'Limpeza do filtro')
            ->assertJsonPath('meta.total', 4);
    }

    public function test_the_range_filter_uses_calendar_days_of_the_tenant(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/occurrences?from=2026-03-25&to=2026-04-04')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.scheduled_for', '2026-03-25')
            ->assertJsonPath('data.1.scheduled_for', '2026-04-04');
    }

    public function test_the_range_accepts_only_calendar_days(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/occurrences?from=15/03/2026')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['from']]]);
    }

    public function test_filters_by_status_and_by_target(): void
    {
        $this->complete('2026-03-25');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/occurrences?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', JobSchedule::STATUS_COMPLETED);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/occurrences?subject_type=bill')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_rejects_an_unknown_status(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/occurrences?status=archived')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['status']]]);
    }

    public function test_completing_returns_the_closed_occurrence_without_a_future_date(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', JobSchedule::STATUS_COMPLETED)
            ->assertJsonPath('data.completed_by', $this->userId())
            ->assertJsonStructure(['data' => ['completed_at']])
            // A próxima data vive na fila e chega pelo evento; devolvê-la aqui
            // seria um valor velho, e o contrato não pode mentir sobre o futuro.
            ->assertJsonMissingPath('next_due_at');
    }

    public function test_completing_an_unknown_occurrence_is_not_found(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/01J8Z0M9W3K6Q2T4R5Y7B8C9D0/complete')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'occurrence_not_found');
    }

    /** O `member` recebe `occurrences.*` por padrão; a revogação explícita é que barra. */
    public function test_each_transition_has_its_own_capability(): void
    {
        $this->revokeCapability('occurrences.skip');

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-04-04').'/skip')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        // Concluir continua liberado: a revogação é da capability, não da pessoa.
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-04-04').'/complete')
            ->assertOk();
    }

    public function test_another_home_cannot_see_or_close_the_occurrence(): void
    {
        // O registro acontece no relógio real e só depois o relógio volta a
        // congelar: o token da segunda morada precisa de `iat` válido.
        CarbonImmutable::setTestNow();
        $other = $this->registerUser('vizinha@mordomus.test', 'Vizinha', 'Casa Vizinha');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

        $this->withHeaders([
            'Authorization' => 'Bearer '.$other['access_token'],
            'Accept' => 'application/json',
        ])->getJson('/api/v1/scheduling/occurrences')->assertOk()->assertJsonCount(0, 'data');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$other['access_token'],
            'Accept' => 'application/json',
        ])->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/complete')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'occurrence_not_found');
    }

    public function test_write_without_a_token_is_unauthorized(): void
    {
        $this->flushHeaders()
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId('2026-03-25').'/complete')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    // ------------------------------------------------ atalho do Maintenance

    public function test_asset_shortcut_completes_the_occurrence_from_the_card(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/occurrences/'.$this->occurrenceId('2026-03-25').'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', JobSchedule::STATUS_COMPLETED)
            ->assertJsonPath('data.subject_type', TriggerConfig::SUBJECT_ASSET);

        // A janela já continha o ciclo seguinte: o check-in recalcula, mas não
        // duplica a data que o materializador tinha adiantado.
        $this->assertSame(
            ['2026-03-25', '2026-04-04', '2026-04-14', '2026-04-24'],
            $this->withTenantContext($this->tenantId, fn (): array => JobSchedule::query()
                ->orderBy('scheduled_for')
                ->get()
                ->map(fn (JobSchedule $occurrence): string => $occurrence->scheduled_for->format('Y-m-d'))
                ->all()),
        );
    }

    public function test_asset_shortcut_skips_the_occurrence_from_the_card(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/occurrences/'.$this->occurrenceId('2026-03-25').'/skip')
            ->assertOk()
            ->assertJsonPath('data.status', JobSchedule::STATUS_SKIPPED);
    }

    public function test_asset_shortcut_does_not_touch_a_bill_occurrence(): void
    {
        // A conta existe de verdade: `trigger_configs.bill_id` tem FK desde
        // `50_financial_links`.
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
                'day_of_month' => 25,
            ]);
        });

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $billOccurrence = $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) JobSchedule::query()
                ->whereHas('triggerConfig', fn ($query) => $query->where('subject_type', TriggerConfig::SUBJECT_BILL))
                ->value('id'),
        );

        // O card do ativo não é o lugar de concluir uma conta: a resposta é 404
        // e nenhum status muda.
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/occurrences/'.$billOccurrence.'/complete')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'occurrence_not_found');

        $this->assertSame(
            JobSchedule::STATUS_PENDING,
            $this->withTenantContext(
                $this->tenantId,
                fn (): string => (string) JobSchedule::query()->where('id', $billOccurrence)->value('status'),
            ),
        );
    }

    // -------------------------------------------------------------- helpers

    private function complete(string $scheduledFor): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/scheduling/occurrences/'.$this->occurrenceId($scheduledFor).'/complete')
            ->assertOk();
    }

    private function occurrenceId(string $scheduledFor): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) JobSchedule::query()->where('scheduled_for', $scheduledFor)->value('id'),
        );
    }

    private function userId(): string
    {
        return $this->withTenantContext($this->tenantId, fn (): string => (string) User::query()->value('id'));
    }

    /**
     * Revoga uma capability da membership do usuário autenticado.
     *
     * O grant do membro tem precedência sobre o papel, e é o caminho que a
     * residência usa para tirar de alguém a permissão de fechar o ciclo alheio.
     */
    private function revokeCapability(string $capability): void
    {
        $membership = $this->withTenantContext($this->tenantId, function () use ($capability): Membership {
            $membership = Membership::query()->firstOrFail();
            $permission = Permission::query()->where('key', $capability)->firstOrFail();

            $membership->permissionGrants()->syncWithoutDetaching([
                $permission->id => ['granted' => false],
            ]);

            return $membership;
        });

        // A resolução é cacheada por 60 s; sem isto o teste leria a capability
        // antiga e o 403 não viria.
        app(CapabilityResolverService::class)->forget($membership);
    }
}
