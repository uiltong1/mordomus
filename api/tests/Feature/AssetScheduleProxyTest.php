<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Maintenance\Models\Asset;
use Mordomus\Maintenance\Models\Room;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Atalho proxied do Maintenance: `POST /assets/{asset}/schedule`.
 *
 * O alvo vem da URL, então o front não monta `subject_type`/`subject_id`, e a
 * regra é gravada pelo módulo Scheduling — dono do cálculo de data.
 */
class AssetScheduleProxyTest extends FeatureTestCase
{
    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('atalho@mordomus.test', 'Atalho', 'Casa A');
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
    }

    public function test_shortcut_creates_the_rule_with_the_asset_as_target(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets/'.$this->assetId.'/schedule', [
                'title' => 'Limpeza do filtro',
                'type' => TriggerConfig::TYPE_INTERVAL,
                'interval_value' => 90,
                'interval_unit' => 'days',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Limpeza do filtro')
            ->assertJsonPath('data.subject_type', TriggerConfig::SUBJECT_ASSET)
            ->assertJsonPath('data.subject_id', $this->assetId)
            ->assertJsonPath('data.interval_value', 90);

        $this->assertNotNull(
            $this->withTenantContext($this->tenantId, fn () => TriggerConfig::query()->first()?->next_due_at),
        );
    }

    public function test_shortcut_needs_no_target_in_the_payload(): void
    {
        // `subject_type`/`subject_id` são da rota; mandar no corpo seria
        // aceitar um alvo diferente do ativo da URL.
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets/'.$this->assetId.'/schedule', [
                'title' => 'Sem alvo no corpo',
                'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
                'day_of_month' => 15,
                'subject_type' => 'bill',
                'subject_id' => '01J8Z0M9W3K6Q2T4R5Y7B8C9D0',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject_type', TriggerConfig::SUBJECT_ASSET)
            ->assertJsonPath('data.subject_id', $this->assetId)
            ->assertJsonPath('data.bill_id', null);
    }

    public function test_repeating_the_title_updates_the_rule(): void
    {
        $first = $this->shortcut(['title' => 'Limpeza do filtro', 'interval_value' => 90]);

        $second = $this->shortcut(['title' => 'Limpeza do filtro', 'interval_value' => 30]);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(30, $second->json('data.interval_value'));
        $this->assertSame(1, $this->ruleCount());
    }

    public function test_shortcut_of_a_foreign_asset_is_not_found(): void
    {
        $other = $this->registerUser('outra-casa-atalho@mordomus.test', 'Outra', 'Casa B');
        $foreignRoom = $this->withTenantContext($other['active_tenant'], fn (): string => Room::create([
            'tenant_id' => $other['active_tenant'],
            'name' => 'Sala B',
            'sort_order' => 0,
        ])->id);

        $foreignAsset = $this->withTenantContext($other['active_tenant'], fn (): string => Asset::create([
            'tenant_id' => $other['active_tenant'],
            'room_id' => $foreignRoom,
            'name' => 'AC da Casa B',
        ])->id);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets/'.$foreignAsset.'/schedule', [
                'title' => 'Regra alheia',
                'type' => TriggerConfig::TYPE_INTERVAL,
                'interval_value' => 30,
                'interval_unit' => 'days',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame(0, $this->ruleCount());
    }

    public function test_shortcut_validates_the_fields_of_the_type(): void
    {
        // Chamada direta: o atalho `shortcut` preencheria os campos de
        // intervalo, que é justamente o que esta requisição precisa omitting.
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets/'.$this->assetId.'/schedule', [
                'title' => 'Sem intervalo',
                'type' => TriggerConfig::TYPE_INTERVAL,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['interval_value', 'interval_unit']]]);
    }

    /** A regra é escrita pelo Scheduling, então a capability é `rules.edit`. */
    public function test_member_cannot_create_the_rule_through_the_shortcut(): void
    {
        $member = User::factory()->create();

        Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $this->asUser($member, $this->tenantId)
            ->postJson('/api/v1/maintenance/assets/'.$this->assetId.'/schedule', [
                'title' => 'Regra de morador',
                'type' => TriggerConfig::TYPE_INTERVAL,
                'interval_value' => 30,
                'interval_unit' => 'days',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required', 'rules.edit');

        $this->assertSame(0, $this->ruleCount());
    }

    public function test_the_rule_is_listable_in_scheduling(): void
    {
        $this->shortcut(['title' => 'Limpeza do filtro']);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/scheduling/trigger-configs?subject_type=asset&subject_id='.$this->assetId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Limpeza do filtro');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shortcut(array $payload): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson(
            '/api/v1/maintenance/assets/'.$this->assetId.'/schedule',
            $payload + [
                'type' => TriggerConfig::TYPE_INTERVAL,
                'interval_value' => 90,
                'interval_unit' => 'days',
            ],
        );
    }

    private function ruleCount(): int
    {
        return $this->withTenantContext($this->tenantId, fn (): int => TriggerConfig::query()->count());
    }
}
