<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Exceptions\IncompleteTriggerRule;
use Mordomus\Scheduling\Models\TriggerConfig;
use Mordomus\Scheduling\Services\TriggerDateService;
use Tests\TestCase;

class TriggerDateServiceTest extends TestCase
{
    private const SAO_PAULO = 'America/Sao_Paulo';

    private TriggerDateService $dates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dates = new TriggerDateService;

        $this->freezeAt('2026-03-15 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function freezeAt(string $instantUtc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instantUtc, 'UTC'));
    }

    public function test_interval_adds_days_to_the_base(): void
    {
        $this->assertSame('2026-06-13', $this->due($this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 90,
            'interval_unit' => 'days',
        ], base: '2026-03-15')));
    }

    public function test_interval_adds_weeks_to_the_base(): void
    {
        $this->assertSame('2026-04-12', $this->due($this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 4,
            'interval_unit' => 'weeks',
        ], base: '2026-03-15')));
    }

    public function test_interval_in_months_does_not_overflow_into_the_next_month(): void
    {
        $config = $this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 1,
            'interval_unit' => 'months',
        ]);

        $this->assertSame('2026-02-28', $this->due($config, base: '2026-01-31'));
        $this->assertSame('2024-02-29', $this->due($config, base: '2024-01-31'));
    }

    public function test_interval_falls_back_to_today_when_there_is_no_base(): void
    {
        $this->assertSame('2026-04-14', $this->due($this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 30,
            'interval_unit' => 'days',
        ])));
    }

    public function test_interval_reads_the_stored_base_date_without_shifting_it(): void
    {
        $this->assertSame('2026-05-14', $this->due($this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'last_base_date' => '2026-04-14',
        ])));
    }

    public function test_interval_one_day_late_keeps_counting_from_the_late_base(): void
    {
        $this->assertSame('2026-05-15', $this->due($this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'last_base_date' => '2026-04-15',
        ])));
    }

    public function test_calendar_monthly_stays_in_the_current_month_when_the_day_is_ahead(): void
    {
        $this->assertSame('2026-03-20', $this->due($this->calendarMonthly(20)));
    }

    public function test_calendar_monthly_advances_when_the_day_already_passed(): void
    {
        $this->assertSame('2026-04-10', $this->due($this->calendarMonthly(10)));
    }

    public function test_calendar_monthly_advances_a_full_month_when_the_base_is_stale(): void
    {

        $this->assertSame('2026-04-05', $this->due($this->calendarMonthly(5, base: '2025-11-20')));
    }

    public function test_calendar_monthly_clamps_day_31_to_the_last_day_of_february(): void
    {

        $this->freezeAt('2026-02-10 12:00:00');
        $this->assertSame('2026-02-28', $this->due($this->calendarMonthly(31, base: '2026-02-10')));

        $this->freezeAt('2024-02-10 12:00:00');
        $this->assertSame('2024-02-29', $this->due($this->calendarMonthly(31, base: '2024-02-10')));
    }

    public function test_calendar_monthly_handles_a_30_day_month_exactly(): void
    {

        $this->freezeAt('2026-04-10 12:00:00');
        $this->assertSame('2026-04-30', $this->due($this->calendarMonthly(30, base: '2026-04-10')));

        $this->freezeAt('2026-04-10 12:00:00');
        $this->assertSame('2026-04-30', $this->due($this->calendarMonthly(31, base: '2026-04-10')));
    }

    public function test_calendar_monthly_on_the_first_day_of_the_month(): void
    {
        $this->assertSame('2026-04-01', $this->due($this->calendarMonthly(1)));
    }

    public function test_post_completion_counts_the_interval_from_the_completion(): void
    {
        $this->assertSame('2026-04-14', $this->due($this->config([
            'type' => TriggerConfig::TYPE_POST_COMPLETION,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'recalculate_base' => TriggerConfig::RECALCULATE_COMPLETION,
        ], base: '2026-03-15')));
    }

    public function test_post_completion_has_no_date_before_the_first_completion(): void
    {
        $this->assertNull($this->dates->nextDue($this->config([
            'type' => TriggerConfig::TYPE_POST_COMPLETION,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'recalculate_base' => TriggerConfig::RECALCULATE_COMPLETION,
        ]), null, self::SAO_PAULO));
    }

    public function test_escalated_advances_by_the_interval(): void
    {
        $this->assertSame('2026-04-14', $this->due($this->config([
            'type' => TriggerConfig::TYPE_ESCALATED,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'custom_offsets' => [-7, -3, 0, 1],
        ], base: '2026-03-15')));
    }

    public function test_escalated_without_a_base_counts_from_today(): void
    {
        $this->assertSame('2026-04-14', $this->due($this->config([
            'type' => TriggerConfig::TYPE_ESCALATED,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'custom_offsets' => [-7, 0],
        ])));
    }

    public function test_today_is_resolved_in_the_tenant_timezone(): void
    {

        $this->assertSame('2026-03-16', $this->dates->nextDue($this->calendarMonthly(16), null, self::SAO_PAULO)?->format('Y-m-d'));
        $this->assertSame('2026-03-16', $this->dates->nextDue($this->calendarMonthly(16), null, 'Asia/Tokyo')?->format('Y-m-d'));

        $this->freezeAt('2026-03-15 23:30:00');

        $this->assertSame('2026-03-15', $this->dates->nextDue($this->calendarMonthly(15), null, self::SAO_PAULO)?->format('Y-m-d'));
        $this->assertSame('2026-04-15', $this->dates->nextDue($this->calendarMonthly(15), null, 'Asia/Tokyo')?->format('Y-m-d'));
    }

    public function test_stored_base_date_is_read_as_a_calendar_date_in_any_timezone(): void
    {
        $config = $this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 1,
            'interval_unit' => 'days',
            'last_base_date' => '2026-03-15',
        ]);

        $this->assertSame('2026-03-16', $this->dates->nextDue($config, null, self::SAO_PAULO)?->format('Y-m-d'));
        $this->assertSame('2026-03-16', $this->dates->nextDue($config, null, 'Asia/Tokyo')?->format('Y-m-d'));
    }

    public function test_due_at_fires_at_the_preferred_hour_in_the_tenant_timezone(): void
    {
        $scheduledFor = CarbonImmutable::parse('2026-03-20', self::SAO_PAULO);

        $this->assertSame(
            '2026-03-20T12:00:00+00:00',
            $this->dates->dueAt($scheduledFor, self::SAO_PAULO, '09:00')->toIso8601String(),
        );

        $this->assertSame(
            '2026-03-21T00:00:00+00:00',
            $this->dates->dueAt($scheduledFor, self::SAO_PAULO, '21:00')->toIso8601String(),
        );
    }

    public function test_due_at_accepts_the_hour_with_seconds(): void
    {
        $scheduledFor = CarbonImmutable::parse('2026-03-20', self::SAO_PAULO);

        $this->assertSame(
            '2026-03-20T12:00:00+00:00',
            $this->dates->dueAt($scheduledFor, self::SAO_PAULO, '09:00:00')->toIso8601String(),
        );
    }

    public function test_a_stored_rule_missing_its_interval_points_at_the_field(): void
    {
        $this->expectException(IncompleteTriggerRule::class);

        $this->dates->nextDue($this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
        ]), null, self::SAO_PAULO);
    }

    public function test_a_stored_rule_missing_its_day_of_month_points_at_the_field(): void
    {
        try {
            $this->dates->nextDue($this->config([
                'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
            ]), null, self::SAO_PAULO);

            $this->fail('Uma regra CALENDAR_MONTHLY sem day_of_month não deveria ter data.');
        } catch (IncompleteTriggerRule $exception) {
            $this->assertSame(['day_of_month'], $exception->details()['missing']);
        }
    }

    private function config(array $attributes, ?string $base = null): TriggerConfig
    {
        return new TriggerConfig($attributes + ['last_base_date' => $base]);
    }

    private function calendarMonthly(int $dayOfMonth, ?string $base = null): TriggerConfig
    {
        return $this->config([
            'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
            'day_of_month' => $dayOfMonth,
        ], $base);
    }

    private function due(TriggerConfig $config, ?string $base = null): string
    {
        $anchor = $base === null ? null : CarbonImmutable::createFromFormat('Y-m-d', $base, self::SAO_PAULO);

        return $this->dates->nextDue($config, $anchor, self::SAO_PAULO)?->format('Y-m-d') ?? '';
    }
}
