<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Mordomus\Notification\Models\NotificationPreference;

class NotificationPreferenceCrudTest extends FeatureTestCase
{
    private string $tenantId;

    private string $userId;

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

        $this->withHeaders($this->headers)
            ->putJson('/api/v1/notification/preferences', ['quiet_start' => '21:00', 'quiet_end' => '06:00'])
            ->assertOk()
            ->assertJsonPath('data.own.digest', null)
            ->assertJsonPath('data.effective.digest', config('notification.defaults.digest'));
    }

    public function test_a_window_with_a_single_side_is_rejected(): void
    {

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
