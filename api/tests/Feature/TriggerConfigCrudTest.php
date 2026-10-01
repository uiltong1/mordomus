<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Mordomus\Financial\Models\Bill;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Regras de recorrência: CRUD, filtro por alvo, validação condicional por
 * tipo, fuso da residência e escrita restrita a `rules.edit`.
 */
class TriggerConfigCrudTest extends FeatureTestCase
{
    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('regras@mordomus.test', 'Regras', 'Casa A');
        $this->tenantId = $auth['active_tenant'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        $this->assetId = $this->createAsset('Ar-condicionado');
    }

    public function test_owner_creates_lists_and_shows_a_rule(): void
    {
        $created = $this->createRule(['title' => 'Trocar o filtro do ar']);

        $created->assertCreated()
            ->assertJsonPath('data.title', 'Trocar o filtro do ar')
            ->assertJsonPath('data.subject_type', 'asset')
            ->assertJsonPath('data.subject_id', $this->assetId)
            ->assertJsonPath('data.type', TriggerConfig::TYPE_INTERVAL)
            ->assertJsonPath('data.interval_value', 90)
            ->assertJsonPath('data.interval_unit', 'days')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.tenant_id', $this->tenantId)
            ->assertJsonPath('data.bill_id', null);

        // R2: a próxima data não vem do pedido, e sim da regra calculada.
        $this->assertNotNull($created->json('data.next_due_at'));

        $id = $created->json('data.id');

        $list = $this->withHeaders($this->headers)->getJson('/api/v1/scheduling/trigger-configs');
        $list->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, $list->json('meta.total'));

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/trigger-configs/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.title', 'Trocar o filtro do ar');
    }

    /** AC 1 — a regra só existe dentro da residência que a criou. */
    public function test_rule_of_another_tenant_is_not_reachable(): void
    {
        $ruleId = $this->createRule()->json('data.id');

        $other = $this->registerUser('outra-casa-regras@mordomus.test', 'Outra', 'Casa B');
        $headers = ['Authorization' => 'Bearer '.$other['access_token'], 'Accept' => 'application/json'];

        $this->withHeaders($headers)->getJson('/api/v1/scheduling/trigger-configs/'.$ruleId)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'trigger_config_not_found');

        $this->withHeaders($headers)->patchJson('/api/v1/scheduling/trigger-configs/'.$ruleId, ['title' => 'invadida'])
            ->assertNotFound();

        $this->withHeaders($headers)->deleteJson('/api/v1/scheduling/trigger-configs/'.$ruleId)
            ->assertNotFound();

        $this->assertTrue($this->ruleExists($ruleId));
    }

    public function test_list_filters_by_target(): void
    {
        $secondAsset = $this->createAsset('Filtro do ar');
        $this->createRule(['title' => 'Regra do AC']);
        $this->createRule(['title' => 'Regra do filtro', 'subject_id' => $secondAsset]);

        $all = $this->withHeaders($this->headers)->getJson('/api/v1/scheduling/trigger-configs');
        $all->assertOk()->assertJsonCount(2, 'data');

        $filtered = $this->withHeaders($this->headers)->getJson(
            '/api/v1/scheduling/trigger-configs?subject_type=asset&subject_id='.$this->assetId
        );
        $filtered->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Regra do AC');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/trigger-configs?subject_type=asset&subject_id='.$secondAsset)
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Regra do filtro');
    }

    public function test_duplicate_title_for_the_same_target_conflicts(): void
    {
        $this->createRule(['title' => 'Limpeza'])->assertCreated();

        $this->createRule(['title' => 'Limpeza'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'trigger_config_exists');
    }

    public function test_same_title_on_another_target_is_allowed(): void
    {
        $this->createRule(['title' => 'Limpeza'])->assertCreated();

        $this->createRule(['title' => 'Limpeza', 'subject_id' => $this->createAsset('Filtro do ar')])
            ->assertCreated();

        $this->assertSame(2, $this->ruleCount());
    }

    /** AC 2 — o erro aponta o campo que falta. */
    public function test_interval_without_its_fields_points_at_them(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => 'asset',
            'subject_id' => $this->assetId,
            'title' => 'Sem intervalo',
            'type' => TriggerConfig::TYPE_INTERVAL,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['interval_value', 'interval_unit']]]);
    }

    public function test_field_from_another_type_is_rejected(): void
    {
        // `day_of_month` não pertence a uma regra INTERVAL: guardar seria lixo
        // silencioso que ninguém encontraria depois.
        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => 'asset',
            'subject_id' => $this->assetId,
            'title' => 'Misturado',
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'day_of_month' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['day_of_month']]]);
    }

    public function test_calendar_monthly_requires_day_of_month(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => 'asset',
            'subject_id' => $this->assetId,
            'title' => 'Mensal',
            'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['day_of_month']]]);
    }

    public function test_escalated_requires_the_interval_and_the_offsets(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => 'asset',
            'subject_id' => $this->assetId,
            'title' => 'Avisos',
            'type' => TriggerConfig::TYPE_ESCALATED,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['interval_value', 'interval_unit', 'custom_offsets']]]);

        // Só `custom_offsets` não fecha: sem intervalo o ciclo não voltaria.
        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => 'asset',
            'subject_id' => $this->assetId,
            'title' => 'Avisos sem intervalo',
            'type' => TriggerConfig::TYPE_ESCALATED,
            'custom_offsets' => [-7, -3, 0, 1],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['interval_value', 'interval_unit']]]);

        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => 'asset',
            'subject_id' => $this->assetId,
            'title' => 'Avisos de atraso',
            'type' => TriggerConfig::TYPE_ESCALATED,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'custom_offsets' => [-7, -3, 0, 1],
        ])
            ->assertCreated()
            ->assertJsonPath('data.custom_offsets', [-7, -3, 0, 1])
            ->assertJsonPath('data.interval_value', 30);
    }

    public function test_day_of_month_out_of_range_is_rejected(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', [
            'subject_type' => 'asset',
            'subject_id' => $this->assetId,
            'title' => 'Dia 45',
            'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
            'day_of_month' => 45,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['day_of_month']]]);
    }

    public function test_patch_touches_only_the_given_fields(): void
    {
        $created = $this->createRule(['title' => 'Nome antigo']);
        $id = $created->json('data.id');
        $nextDueAt = $created->json('data.next_due_at');

        $patched = $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$id, ['title' => 'Nome novo'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Nome novo')
            ->assertJsonPath('data.interval_value', 90);

        // Renomear não mexe na matemática: a data não pode andar sozinha.
        $this->assertSame($nextDueAt, $patched->json('data.next_due_at'));
    }

    public function test_patch_of_the_interval_recalculates_the_next_date(): void
    {
        $id = $this->createRule(['title' => 'Filtro'])->json('data.id');
        $before = $this->showRule($id)->json('data.next_due_at');

        $after = $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$id, ['interval_value' => 30, 'interval_unit' => 'days'])
            ->assertOk()
            ->assertJsonPath('data.interval_value', 30);

        $this->assertNotSame($before, $after->json('data.next_due_at'));
    }

    public function test_patch_that_changes_the_type_clears_the_previous_fields(): void
    {
        $id = $this->createRule(['title' => 'Filtro'])->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$id, [
                'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
                'day_of_month' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('data.type', TriggerConfig::TYPE_CALENDAR_MONTHLY)
            ->assertJsonPath('data.day_of_month', 15)
            ->assertJsonPath('data.interval_value', null)
            ->assertJsonPath('data.interval_unit', null);
    }

    public function test_patch_that_changes_the_type_demands_the_new_fields(): void
    {
        $id = $this->createRule(['title' => 'Filtro'])->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$id, ['type' => TriggerConfig::TYPE_ESCALATED])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['interval_value', 'interval_unit', 'custom_offsets']]]);
    }

    public function test_patch_without_any_field_is_rejected(): void
    {
        $id = $this->createRule()->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$id, [])
            ->assertStatus(422);
    }

    public function test_delete_removes_the_rule(): void
    {
        $id = $this->createRule()->json('data.id');

        $this->withHeaders($this->headers)
            ->deleteJson('/api/v1/scheduling/trigger-configs/'.$id)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/trigger-configs/'.$id)
            ->assertNotFound();

        $this->assertSame(0, $this->ruleCount());
    }

    public function test_is_active_pauses_the_rule_without_deleting_it(): void
    {
        $id = $this->createRule()->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/trigger-configs/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    /** T3.1.4 — sem `preferred_hour` na regra, vale o da residência. */
    public function test_preferred_hour_falls_back_to_the_tenant(): void
    {
        $created = $this->createRule();

        $this->assertNull($created->json('data.preferred_hour'));
        $this->assertSame('12:00:00Z', $this->utcTimeOf($created->json('data.next_due_at')));
    }

    public function test_preferred_hour_on_the_rule_wins_over_the_tenant(): void
    {
        $created = $this->createRule(['preferred_hour' => '18:00']);

        $this->assertSame('18:00', $created->json('data.preferred_hour'));
        $this->assertSame('21:00:00Z', $this->utcTimeOf($created->json('data.next_due_at')));
    }

    public function test_next_date_follows_the_tenants_timezone(): void
    {
        // Mesma regra, mesma âncora: o horário do disparo muda com o fuso da
        // residência, e o dia do calendário continua o mesmo.
        $saoPaulo = $this->createRule();

        Tenant::query()->whereKey($this->tenantId)->update(['timezone' => 'UTC']);

        $utc = $this->createRule(['title' => 'Filtro em UTC']);

        $this->assertNotSame(
            $this->utcTimeOf($saoPaulo->json('data.next_due_at')),
            $this->utcTimeOf($utc->json('data.next_due_at')),
        );
    }

    public function test_member_can_read_but_cannot_write(): void
    {
        $id = $this->createRule()->json('data.id');
        $member = $this->makeMember();

        $this->asUser($member, $this->tenantId)
            ->getJson('/api/v1/scheduling/trigger-configs')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asUser($member, $this->tenantId)
            ->postJson('/api/v1/scheduling/trigger-configs', [
                'subject_type' => 'asset',
                'subject_id' => $this->assetId,
                'title' => 'Regra de morador',
                'type' => TriggerConfig::TYPE_INTERVAL,
                'interval_value' => 30,
                'interval_unit' => 'days',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required', 'rules.edit');

        $this->asUser($member, $this->tenantId)
            ->patchJson('/api/v1/scheduling/trigger-configs/'.$id, ['title' => 'x'])
            ->assertForbidden();

        $this->asUser($member, $this->tenantId)
            ->deleteJson('/api/v1/scheduling/trigger-configs/'.$id)
            ->assertForbidden();
    }

    public function test_writes_require_a_token(): void
    {
        // O setUp monta os ativos com `withHeaders`, que deixa o token nos
        // headers padrão do teste; sem limpá-lo esta requisição não seria
        // anônima.
        $this->flushHeaders();

        $this->postJson('/api/v1/scheduling/trigger-configs', [])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    /**
     * O caminho de `bill` grava a coluna certa — e a coluna tem FK de verdade
     * desde `50_financial_links`, o que o esquema do Financial verifica no
     * nível do banco.
     */
    public function test_bill_rule_records_the_bill_column(): void
    {
        $billId = $this->createBill('Conta de luz');

        $this->createRule([
            'subject_type' => 'bill',
            'subject_id' => $billId,
            'title' => 'Pagar conta de luz',
        ])
            ->assertCreated()
            ->assertJsonPath('data.subject_type', 'bill')
            ->assertJsonPath('data.subject_id', $billId)
            ->assertJsonPath('data.asset_id', null);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRule(array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/trigger-configs', $overrides + [
            'subject_type' => 'asset',
            'subject_id' => $this->assetId,
            'title' => 'Trocar o filtro do ar',
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 90,
            'interval_unit' => 'days',
        ]);
    }

    private function showRule(string $id): TestResponse
    {
        return $this->withHeaders($this->headers)->getJson('/api/v1/scheduling/trigger-configs/'.$id);
    }

    private function createBill(string $name): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => Bill::create([
                'tenant_id' => $this->tenantId,
                'name' => $name,
                'kind' => Bill::KIND_FIXED,
                'amount' => '150.00',
            ])->id,
        );
    }

    private function createAsset(string $name): string
    {
        $roomId = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Sala'])
            ->assertCreated()
            ->json('data.id');

        return $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets', ['room_id' => $roomId, 'name' => $name])
            ->assertCreated()
            ->json('data.id');
    }

    private function makeMember(): User
    {
        $member = User::factory()->create();

        Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        return $member;
    }

    /** Um novo JWT depois de mexer na residência, para o `tid` continuar válido. */
    private function refreshTokens(): void
    {
        $user = User::query()
            ->whereHas('memberships', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->firstOrFail();

        $this->headers = $this->authHeadersFor($user, $this->tenantId);
    }

    private function ruleExists(string $id): bool
    {
        return $this->withTenantContext($this->tenantId, fn (): bool => TriggerConfig::query()->whereKey($id)->exists());
    }

    private function ruleCount(): int
    {
        return $this->withTenantContext($this->tenantId, fn (): int => TriggerConfig::query()->count());
    }

    /** Só a hora do instante, já em UTC — o dia é o mesmo para todos os fusos testados. */
    private function utcTimeOf(?string $isoInstant): string
    {
        return CarbonImmutable::parse((string) $isoInstant)->utc()->format('H:i:s\Z');
    }
}
