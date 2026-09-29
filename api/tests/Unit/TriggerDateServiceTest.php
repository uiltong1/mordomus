<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Exceptions\IncompleteTriggerRule;
use Mordomus\Scheduling\Models\TriggerConfig;
use Mordomus\Scheduling\Services\TriggerDateService;
use Tests\TestCase;

/**
 * A matemática de `nextDue` nos 4 tipos.
 *
 * O relógio é congelado: qualquer teste que dependesse do dia em que roda
 * falharia amanhã. O fuso entra como parâmetro, e não como global, para que
 * o comportamento de cada residência fique explícito no próprio cenário.
 */
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

    /** Congela o relógio em UTC — sem isso o resultado mudaria todo dia. */
    private function freezeAt(string $instantUtc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instantUtc, 'UTC'));
    }

    // ---------------------------------------------------------------- INTERVAL

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

    /** 31 de janeiro + 1 mês é o último dia de fevereiro, não 2 de março. */
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

    /** Tarefa atrasada: a âncora é a última base, não hoje. */
    public function test_interval_one_day_late_keeps_counting_from_the_late_base(): void
    {
        $this->assertSame('2026-05-15', $this->due($this->config([
            'type' => TriggerConfig::TYPE_INTERVAL,
            'interval_value' => 30,
            'interval_unit' => 'days',
            'last_base_date' => '2026-04-15',
        ])));
    }

    // ---------------------------------------------------------- CALENDAR_MONTHLY

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
        // A âncora de novembro ainda deixaria a data no passado com um único
        // avanço; a data precisa cair no próximo dia 5 futuro.
        $this->assertSame('2026-04-05', $this->due($this->calendarMonthly(5, base: '2025-11-20')));
    }

    public function test_calendar_monthly_clamps_day_31_to_the_last_day_of_february(): void
    {
        // Fevereiro é o único mês que não tem dia 31: a regra do dia 31 tem de
        // virar o último dia, senão transbordaria para março.
        $this->freezeAt('2026-02-10 12:00:00');
        $this->assertSame('2026-02-28', $this->due($this->calendarMonthly(31, base: '2026-02-10')));

        $this->freezeAt('2024-02-10 12:00:00');
        $this->assertSame('2024-02-29', $this->due($this->calendarMonthly(31, base: '2024-02-10')));
    }

    public function test_calendar_monthly_handles_a_30_day_month_exactly(): void
    {
        // Abril tem 30 dias: o dia 30 existe e o dia 31 é preso a ele.
        $this->freezeAt('2026-04-10 12:00:00');
        $this->assertSame('2026-04-30', $this->due($this->calendarMonthly(30, base: '2026-04-10')));

        $this->freezeAt('2026-04-10 12:00:00');
        $this->assertSame('2026-04-30', $this->due($this->calendarMonthly(31, base: '2026-04-10')));
    }

    public function test_calendar_monthly_on_the_first_day_of_the_month(): void
    {
        $this->assertSame('2026-04-01', $this->due($this->calendarMonthly(1)));
    }

    // --------------------------------------------------------- POST_COMPLETION

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

    // ---------------------------------------------------------------- ESCALATED

    /** Os offsets geram N avisos; a data em si não se move. */
    public function test_escalated_keeps_the_base_date(): void
    {
        $this->assertSame('2026-03-15', $this->due($this->config([
            'type' => TriggerConfig::TYPE_ESCALATED,
            'custom_offsets' => [-7, -3, 0, 1],
        ], base: '2026-03-15')));
    }

    public function test_escalated_has_no_date_without_a_base(): void
    {
        $this->assertNull($this->dates->nextDue($this->config([
            'type' => TriggerConfig::TYPE_ESCALATED,
            'custom_offsets' => [-7, 0],
        ]), null, self::SAO_PAULO));
    }

    // -------------------------------------------------------------------- FUSO

    /**
     * O mesmo instante é "hoje" num fuso e "amanhã" no outro: a comparação que
     * decide avançar o mês tem de ser feita no fuso da residência.
     */
    public function test_today_is_resolved_in_the_tenant_timezone(): void
    {
        // 2026-03-15 12:00 UTC é 15/03 09:00 em São Paulo e 15/03 21:00 em
        // Tokyo — mesmo dia nos dois. O caso que separa os fusos é 23:00 UTC,
        // que já é o dia 16 em Tokyo e ainda 15 em São Paulo; com o relógio
        // congelado em 12:00 UTC o dia 16 ainda não chegou em lugar nenhum,
        // então a regra do dia 16 fica no mês corrente nos dois fusos.
        $this->assertSame('2026-03-16', $this->dates->nextDue($this->calendarMonthly(16), null, self::SAO_PAULO)?->format('Y-m-d'));
        $this->assertSame('2026-03-16', $this->dates->nextDue($this->calendarMonthly(16), null, 'Asia/Tokyo')?->format('Y-m-d'));

        // 15/03 20:30 em São Paulo, mas 16/03 08:30 em Tokyo. A regra do dia 15
        // já é hoje em São Paulo e ainda vai acontecer em Tokyo — no mesmo
        // instante, a resposta muda com o fuso da residência.
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

        // 09:00 em São Paulo (UTC-3) é 12:00 do mesmo dia em UTC.
        $this->assertSame(
            '2026-03-20T12:00:00+00:00',
            $this->dates->dueAt($scheduledFor, self::SAO_PAULO, '09:00')->toIso8601String(),
        );

        // 21:00 em São Paulo já é meia-noite do dia seguinte em UTC — a
        // ocorrência continua pertencendo a 20/03 no calendário da residência.
        $this->assertSame(
            '2026-03-21T00:00:00+00:00',
            $this->dates->dueAt($scheduledFor, self::SAO_PAULO, '21:00')->toIso8601String(),
        );
    }

    /** O `time` do PostgreSQL volta como `HH:MM:SS`; os segundos não podem empurrar o disparo. */
    public function test_due_at_accepts_the_hour_with_seconds(): void
    {
        $scheduledFor = CarbonImmutable::parse('2026-03-20', self::SAO_PAULO);

        $this->assertSame(
            '2026-03-20T12:00:00+00:00',
            $this->dates->dueAt($scheduledFor, self::SAO_PAULO, '09:00:00')->toIso8601String(),
        );
    }

    // ------------------------------------------------------------- INCOMPLETAS

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

    /**
     * @param  array<string, mixed>  $attributes
     */
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
