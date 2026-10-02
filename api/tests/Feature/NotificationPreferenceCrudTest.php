<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Mordomus\Notification\Models\NotificationPreference;

/**
 * Quiet hours, digest e horário preferido, com a precedência resolvida.
 */
class NotificationPreferenceCrudTest extends FeatureTestCase
{
    private string $tenantId;

    private string $userId;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('pref@mordomus.test', 'Pref', 'Casa da Pref');
        $this->tenantId = $auth['active_tenant'];
        $this->userId = $auth['user']['id'];
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

    public function test_without_any_configuration_the_environment_default_answers(): void
    {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/notification/preferences')
            ->assertOk()
            ->assertJsonPath('data.own', null)
            ->assertJsonPath('data.house', null)
            ->assertJsonPath('data.effective.digest', config('notification.defaults.digest'))
            ->assertJsonPath('data.effective.quiet_start', '22:00')
            ->assertJsonPath('data.effective.quiet_end', '07:00')
            // A tela precisa saber se a janela atravessa a meia-noite, e é o
            // fuso que faz essa janela existir: 22:00–07:00 é uma janela só.
            ->assertJsonPath('data.effective.quiet_window_wraps_midnight', true);
    }

    public function test_the_write_only_touches_the_line_of_the_morador(): void
    {
        $this->houseDefault(['quiet_start' => '23:00', 'quiet_end' => '06:00']);

        $this->withHeaders($this->headers)
            ->putJson('/api/v1/notification/preferences', [
                'quiet_start' => '22:00',
                'quiet_end' => '07:00',
                'digest' => 'daily',
                'preferred_hour' => '08:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.own.quiet_start', '22:00')
            ->assertJsonPath('data.own.digest', 'daily')
            ->assertJsonPath('data.effective.preferred_hour', '08:00');

        // Quem escreve na própria linha nunca mexe no padrão da casa: ele é
        // decisão de quem administra a residência.
        $this->assertSame('23:00', $this->houseDefaultRow()->quiet_start?->format('H:i'));
        $this->assertNull($this->houseDefaultRow()->user_id);
    }

    public function test_the_precedence_is_field_by_field_and_not_row_by_row(): void
    {
        $this->houseDefault([
            'quiet_start' => '23:00',
            'quiet_end' => '06:00',
            'digest' => 'daily',
            'preferred_hour' => '10:00',
        ]);

        $this->withHeaders($this->headers)
            ->putJson('/api/v1/notification/preferences', [
                'quiet_start' => '22:00',
                'quiet_end' => '07:00',
                'digest' => 'instant',
            ])
            ->assertOk()
            // A janela e o digest são do morador; o horário preferido, que ele
            // não mandou, continua sendo o da casa. Uma linha inteira
            // venceria a casa inteira por causa de um campo.
            ->assertJsonPath('data.effective.quiet_start', '22:00')
            ->assertJsonPath('data.effective.digest', 'instant')
            ->assertJsonPath('data.effective.preferred_hour', '10:00')
            ->assertJsonPath('data.house.preferred_hour', '10:00');
    }

    public function test_a_field_left_out_goes_back_to_the_default(): void
    {
        $this->withHeaders($this->headers)
            ->putJson('/api/v1/notification/preferences', ['digest' => 'daily'])
            ->assertOk()
            ->assertJsonPath('data.effective.digest', 'daily');

        // O PUT substitui o conjunto: um campo que sai do payload é um campo
        // que o morador quer voltar ao padrão.
        $this->withHeaders($this->headers)
            ->putJson('/api/v1/notification/preferences', ['quiet_start' => '21:00', 'quiet_end' => '06:00'])
            ->assertOk()
            ->assertJsonPath('data.own.digest', null)
            ->assertJsonPath('data.effective.digest', config('notification.defaults.digest'));
    }

    public function test_a_window_with_a_single_side_is_rejected(): void
    {
        // Meia janela não existe: silêncio sem fim de noite é o morador sem
        // notificação a vida inteira, por causa de um campo que ele esqueceu.
        $this->withHeaders($this->headers)
            ->putJson('/api/v1/notification/preferences', ['quiet_start' => '22:00'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['quiet_end']]]);
    }

    public function test_a_digest_outside_the_enum_is_rejected(): void
    {
        $this->withHeaders($this->headers)
            ->putJson('/api/v1/notification/preferences', ['digest' => 'semanal'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['digest']]]);
    }

    public function test_an_hour_outside_the_format_is_rejected(): void
    {
        $this->withHeaders($this->headers)
            ->putJson('/api/v1/notification/preferences', ['preferred_hour' => '9h'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['preferred_hour']]]);
    }

    public function test_the_reads_demand_a_token(): void
    {
        $this->getJson('/api/v1/notification/preferences')->assertUnauthorized();
        $this->putJson('/api/v1/notification/preferences', ['digest' => 'daily'])->assertUnauthorized();
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $attributes */
    private function houseDefault(array $attributes): NotificationPreference
    {
        return $this->withTenantContext($this->tenantId, fn (): NotificationPreference => NotificationPreference::create([
            'tenant_id' => $this->tenantId,
            'user_id' => null,
            ...$attributes,
        ]));
    }

    private function houseDefaultRow(): NotificationPreference
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): NotificationPreference => NotificationPreference::query()->whereNull('user_id')->firstOrFail(),
        );
    }
}
