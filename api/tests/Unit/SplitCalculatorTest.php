<?php

namespace Tests\Unit;

use Mordomus\Financial\Exceptions\SplitNotComputable;
use Mordomus\Financial\Models\SplitEntry;
use Mordomus\Financial\Models\SplitRule;
use Mordomus\Financial\Services\MoneyService;
use Mordomus\Financial\Services\SplitCalculator;
use Tests\TestCase;

/**
 * A matemática da divisão nos quatro regimes (T5.2.2, T5.2.3).
 *
 * O que se prova aqui não é só o número de cada cota, é a invariante da regra
 * R5: a soma das cotas é o valor do lançamento, com centavo, em todos os
 * cenários. Por isso todo cenário termina com a soma conferida — é a resposta
 * ao risco apontado no plano, "split com arredondamento quebrando soma".
 *
 * O morador é um id, e não um model: o cálculo não conhece banco, e o teste
 * não precisa de um para provar a parte que é só aritmética.
 */
class SplitCalculatorTest extends TestCase
{
    private SplitCalculator $calculator;

    private MoneyService $money;

    protected function setUp(): void
    {
        parent::setUp();

        $this->money = new MoneyService;
        $this->calculator = new SplitCalculator($this->money);
    }

    // ----------------------------------------------------------------- EQUAL

    public function test_equal_gives_the_same_share_to_everyone(): void
    {
        $this->assertShares(
            ['100.00', '100.00', '100.00'],
            '300.00',
            SplitRule::MODE_EQUAL,
            $this->residents(3),
        );
    }

    /** 2 moradores: o centavo de sobra vai inteiro para a última cota. */
    public function test_equal_with_two_residents_puts_the_remainder_on_the_last_one(): void
    {
        $this->assertShares(
            ['50.00', '50.01'],
            '100.01',
            SplitRule::MODE_EQUAL,
            $this->residents(2),
        );
    }

    /**
     * 187,43 por três: cada um leva 62,47 e a última leva a sobra das duas
     * primeiras. É a regra R5 — a diferença de centavo fica na última cota, e
     * não espalhada pelas três.
     */
    public function test_equal_with_three_residents_closes_on_the_cents(): void
    {
        $this->assertShares(
            ['62.47', '62.47', '62.49'],
            '187.43',
            SplitRule::MODE_EQUAL,
            $this->residents(3),
        );
    }

    public function test_equal_with_five_residents_closes_on_the_cents(): void
    {
        $this->assertShares(
            ['18.90', '18.90', '18.90', '18.90', '18.92'],
            '94.52',
            SplitRule::MODE_EQUAL,
            $this->residents(5),
        );
    }

    /** Cinco moradores e um total que não é múltiplo de cinco. */
    public function test_equal_with_five_residents_keeps_the_cents(): void
    {
        $this->assertShares(
            ['20.00', '20.00', '20.00', '20.00', '20.01'],
            '100.01',
            SplitRule::MODE_EQUAL,
            $this->residents(5),
        );
    }

    public function test_equal_of_a_zero_amount_is_zero_for_everyone(): void
    {
        $this->assertShares(
            ['0.00', '0.00'],
            '0.00',
            SplitRule::MODE_EQUAL,
            $this->residents(2),
        );
    }

    public function test_equal_of_a_single_resident_is_the_whole_bill(): void
    {
        $this->assertShares(
            ['187.43'],
            '187.43',
            SplitRule::MODE_EQUAL,
            $this->residents(1),
        );
    }

    // -------------------------------------------------------------- WEIGHTED

    public function test_weighted_follows_the_weight_of_each_resident(): void
    {
        $this->assertShares(
            ['100.00', '200.00', '100.00'],
            '400.00',
            SplitRule::MODE_WEIGHTED,
            $this->entries([['weight' => '1.00'], ['weight' => '2.00'], ['weight' => '1.00']]),
        );
    }

    /** Pesos fracionários são o motivo de o cálculo ser em centésimos. */
    public function test_weighted_accepts_fractional_weights(): void
    {
        $this->assertShares(
            ['41.66', '62.50', '83.34'],
            '187.50',
            SplitRule::MODE_WEIGHTED,
            $this->entries([['weight' => '1.00'], ['weight' => '1.50'], ['weight' => '2.00']]),
        );
    }

    public function test_weighted_that_does_not_divide_evenly_closes_on_the_cents(): void
    {
        $this->assertShares(
            ['62.47', '124.96'],
            '187.43',
            SplitRule::MODE_WEIGHTED,
            $this->entries([['weight' => '1.00'], ['weight' => '2.00']]),
        );
    }

    public function test_weighted_of_a_zero_amount_is_zero_for_everyone(): void
    {
        $this->assertShares(
            ['0.00', '0.00', '0.00'],
            '0.00',
            SplitRule::MODE_WEIGHTED,
            $this->entries([['weight' => '1.00'], ['weight' => '2.00'], ['weight' => '3.00']]),
        );
    }

    // --------------------------------------------------------------- PERCENT

    public function test_percent_follows_the_percentage_of_each_resident(): void
    {
        $this->assertShares(
            ['400.00', '300.00', '300.00'],
            '1000.00',
            SplitRule::MODE_PERCENT,
            $this->entries([['percent' => '40.00'], ['percent' => '30.00'], ['percent' => '30.00']]),
        );
    }

    /**
     * Percentuais que não fecham em 100 são a proporção que o morador quis
     * dizer: 30/30/30 é um terço para cada, e tratá-lo como erro devolveria um
     * problema que ele não tem.
     */
    public function test_percent_normalizes_a_sum_that_is_not_a_hundred(): void
    {
        $this->assertShares(
            ['62.47', '62.47', '62.49'],
            '187.43',
            SplitRule::MODE_PERCENT,
            $this->entries([['percent' => '30.00'], ['percent' => '30.00'], ['percent' => '30.00']]),
        );
    }

    public function test_percent_that_passes_a_hundred_closes_on_the_total(): void
    {
        $this->assertShares(
            ['60.60', '60.60', '45.47'],
            '166.67',
            SplitRule::MODE_PERCENT,
            $this->entries([['percent' => '40.00'], ['percent' => '40.00'], ['percent' => '30.00']]),
        );
    }

    public function test_percent_with_two_residents_closes_on_the_cents(): void
    {
        $this->assertShares(
            ['100.00', '100.01'],
            '200.01',
            SplitRule::MODE_PERCENT,
            $this->entries([['percent' => '50.00'], ['percent' => '50.00']]),
        );
    }

    public function test_percent_with_five_residents_closes_on_the_cents(): void
    {
        $this->assertShares(
            ['18.90', '18.90', '18.90', '18.90', '18.90'],
            '94.50',
            SplitRule::MODE_PERCENT,
            $this->entries(array_fill(0, 5, ['percent' => '20.00'])),
        );
    }

    // ---------------------------------------------------------------- CUSTOM

    public function test_custom_uses_the_amount_written_for_each_resident(): void
    {
        $this->assertShares(
            ['750.00', '250.00', '0.00'],
            '1000.00',
            SplitRule::MODE_CUSTOM,
            $this->entries([
                ['fixed_amount' => '750.00'],
                ['fixed_amount' => '250.00'],
                ['fixed_amount' => '0.00'],
            ]),
        );
    }

    /**
     * Cota fechada que não fecha o total: a diferença cai na última, que é o
     * que a R5 descreve.
     */
    public function test_custom_closes_the_difference_on_the_last_share(): void
    {
        $this->assertShares(
            ['60.00', '65.43'],
            '125.43',
            SplitRule::MODE_CUSTOM,
            $this->entries([['fixed_amount' => '60.00'], ['fixed_amount' => '60.00']]),
        );
    }

    /** Sobra que a última cota absorve sem ficar negativa é um combinado válido. */
    public function test_custom_can_cover_a_sum_below_the_total(): void
    {
        $this->assertShares(
            ['80.00', '20.00'],
            '100.00',
            SplitRule::MODE_CUSTOM,
            $this->entries([['fixed_amount' => '80.00'], ['fixed_amount' => '50.00']]),
        );
    }

    /**
     * Cota negativa é dívida invertida, e a regra que a produziria está
     * errada — por isso a recusa vem explícita em vez de a linha ser gravada.
     */
    public function test_custom_that_would_leave_a_negative_share_is_refused(): void
    {
        $this->expectException(SplitNotComputable::class);

        $this->calculator->shares(
            $this->rule(SplitRule::MODE_CUSTOM),
            $this->entries([['fixed_amount' => '120.00'], ['fixed_amount' => '50.00']]),
            '100.00',
        );
    }

    // -------------------------------------------------------------- recusas

    public function test_a_rule_without_residents_cannot_divide(): void
    {
        $this->expectException(SplitNotComputable::class);

        $this->calculator->shares($this->rule(SplitRule::MODE_EQUAL), [], '100.00');
    }

    public function test_weights_that_sum_to_zero_cannot_divide(): void
    {
        $this->expectException(SplitNotComputable::class);

        $this->calculator->shares(
            $this->rule(SplitRule::MODE_WEIGHTED),
            $this->entries([['weight' => '0.00'], ['weight' => '0.00']]),
            '100.00',
        );
    }

    public function test_percentages_that_sum_to_zero_cannot_divide(): void
    {
        $this->expectException(SplitNotComputable::class);

        $this->calculator->shares(
            $this->rule(SplitRule::MODE_PERCENT),
            $this->entries([['percent' => '0.00'], ['percent' => '0.00']]),
            '100.00',
        );
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->expectException(SplitNotComputable::class);

        $this->calculator->shares($this->rule('LOTERIA'), $this->residents(2), '100.00');
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $expected
     * @param  list<SplitEntry>  $entries
     */
    private function assertShares(array $expected, string $total, string $mode, array $entries): void
    {
        $shares = $this->calculator->shares($this->rule($mode), $entries, $total);

        $this->assertSame($expected, array_column($shares, 'amount'));
        $this->assertCount(count($entries), $shares);

        // A invariante da R5, conferida em todos os cenários: é ela que o plano
        // aponta como o risco a não perder de vista.
        $this->assertSame(
            $total,
            $this->money->sum(array_column($shares, 'amount')),
            'A soma das cotas precisa ser o valor do lançamento (R5).',
        );
    }

    private function rule(string $mode): SplitRule
    {
        return new SplitRule(['mode' => $mode]);
    }

    /**
     * @return list<SplitEntry>
     */
    private function residents(int $count): array
    {
        return array_map(
            fn (int $index): SplitEntry => $this->entry($index, []),
            range(1, $count),
        );
    }

    /**
     * @param  list<array<string, string>>  $fields
     * @return list<SplitEntry>
     */
    private function entries(array $fields): array
    {
        return array_map(
            fn (array $field, int $index): SplitEntry => $this->entry($index + 1, $field),
            array_values($fields),
            array_keys(array_values($fields)),
        );
    }

    /** @param array<string, string> $field */
    private function entry(int $index, array $field): SplitEntry
    {
        return new SplitEntry(['user_id' => 'user-'.$index] + $field);
    }
}
