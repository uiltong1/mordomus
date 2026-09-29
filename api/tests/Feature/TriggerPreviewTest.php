<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * `POST /preview`: a mesma matemática do CRUD, sem gravar nada.
 *
 * O preview é o que o formulário do front usa para mostrar a próxima data
 * enquanto a regra está sendo montada — por isso o AC de R2 importa ainda
 * mais aqui: a resposta vem da regra, nunca de um cálculo solto no front.
 */
class TriggerPreviewTest extends FeatureTestCase
{
    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('preview@mordomus.test', 'Preview', 'Casa A');
        $this->tenantId = $auth['active_tenant'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_preview_calculates_an_interval_without_persisting(): void
    {
        $this->preview(['type' => TriggerConfig::TYPE_INTERVAL, 'interval_value' => 90, 'interval_unit' => 'days'])
            ->assertOk()
            ->assertJsonPath('data.type', TriggerConfig::TYPE_INTERVAL)
            ->assertJsonPath('data.scheduled_for', '2026-06-13')
            ->assertJsonPath('data.preferred_hour', '09:00')
            ->assertJsonPath('data.timezone', 'America/Sao_Paulo');

        $this->assertSame(0, $this->ruleCount());
    }

    public function test_preview_answers_with_an_instant_in_the_tenant_timezone(): void
    {
        // 09:00 em São Paulo (UTC-3) é 12:00 UTC do mesmo dia.
        $this->preview(['type' => TriggerConfig::TYPE_INTERVAL, 'interval_value' => 1, 'interval_unit' => 'days'])
            ->assertOk()
            ->assertJsonPath('data.next_due_at', '2026-03-16T12:00:00+00:00');
    }

    public function test_preview_honours_the_base_of_the_cycle(): void
    {
        $this->preview([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'base' => '2026-01-15',
        ])
            ->assertOk()
            ->assertJsonPath('data.scheduled_for', '2026-02-14');
    }

    public function test_preview_clamps_calendar_monthly_day_31_on_february(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-10 12:00:00', 'UTC'));

        $this->preview(['type' => TriggerConfig::TYPE_CALENDAR_MONTHLY, 'day_of_month' => 31])
            ->assertOk()
            ->assertJsonPath('data.scheduled_for', '2026-02-28');
    }

    public function test_preview_of_post_completion_without_base_is_null(): void
    {
        $this->preview([
            'type' => TriggerConfig::TYPE_POST_COMPLETION,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'recalculate_base' => TriggerConfig::RECALCULATE_COMPLETION,
        ])
            ->assertOk()
            ->assertJsonPath('data.scheduled_for', null)
            ->assertJsonPath('data.next_due_at', null);
    }

    public function test_preview_of_escalated_counts_from_today(): void
    {
        // `ESCALATED` repete pelo intervalo como o `INTERVAL`; os offsets só
        // multiplicam os avisos, e nenhum deles desloca a data.
        $this->preview([
            'type' => TriggerConfig::TYPE_ESCALATED,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'custom_offsets' => [-7, 0, 1],
        ])
            ->assertOk()
            ->assertJsonPath('data.scheduled_for', '2026-04-14')
            ->assertJsonPath('data.next_due_at', '2026-04-14T12:00:00+00:00');
    }

    public function test_preview_uses_the_preferred_hour_from_the_payload(): void
    {
        $this->preview([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 1,
            'interval_unit' => 'days',
            'preferred_hour' => '18:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.preferred_hour', '18:00')
            ->assertJsonPath('data.next_due_at', '2026-03-16T21:00:00+00:00');
    }

    public function test_preview_validates_the_fields_of_the_type(): void
    {
        $this->preview(['type' => TriggerConfig::TYPE_INTERVAL])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['interval_value', 'interval_unit']]]);
    }

    public function test_preview_rejects_a_field_from_another_type(): void
    {
        $this->preview([
            'type' => TriggerConfig::TYPE_ESCALATED,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'custom_offsets' => [0],
            'day_of_month' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['day_of_month']]]);
    }

    public function test_preview_rejects_an_unknown_type(): void
    {
        $this->preview(['type' => 'SEMANAL'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['type']]]);
    }

    public function test_preview_does_not_need_a_target(): void
    {
        // O front chama o preview antes de o ativo existir em alguns fluxos; a
        // matemática da data não depende do alvo.
        $this->preview(['type' => TriggerConfig::TYPE_CALENDAR_MONTHLY, 'day_of_month' => 20])
            ->assertOk()
            ->assertJsonPath('data.scheduled_for', '2026-03-20');
    }

    public function test_preview_requires_a_resident_tenant(): void
    {
        // O setUp monta a sessão com `withHeaders`, que deixa o token nos
        // headers padrão do teste; sem limpá-lo esta chamada não seria anônima.
        $this->flushHeaders();

        $this->postJson('/api/v1/scheduling/preview', [
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 1,
            'interval_unit' => 'days',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function preview(array $payload): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson('/api/v1/scheduling/preview', $payload);
    }

    private function ruleCount(): int
    {
        return $this->withTenantContext($this->tenantId, fn (): int => TriggerConfig::query()->count());
    }
}
