<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitCalculatorInterface;
use Mordomus\Financial\Exceptions\SplitNotComputable;
use Mordomus\Financial\Models\SplitEntry;
use Mordomus\Financial\Models\SplitRule;

/**
 * Os quatro regimes de divisão, com a soma batendo com o total (regra R5).
 *
 * A R5 é o motivo de esta classe existir em vez de uma linha de multiplicação:
 * a soma das cotas tem de ser o valor do lançamento em 100% dos cenários, e
 * dividir em ponto flutuante fecha na maioria das vezes e erra justamente nos
 * centavos. Por isso tudo aqui é inteiro — centavos de um lado, pesos e
 * percentuais convertidos para centésimos do outro — e o resíduo vai sempre
 * para a última entrada, que é o que a regra descreve e o que deixa a
 * diferença de centavo num lugar só e visível, em vez de espalhada em três
 * valores que ninguém sabe explicar.
 *
 * Três decisões que o código acima não explica sozinho:
 *
 * - `PERCENT` normaliza pela soma dos percentuais, e não por 100. O morador
 *   que digita 30/30/30 quer dizer um terço para cada um; tratar 90 como erro
 *   devolveria um problema que ele não tem, e o `SplitModeRules` já proíbe
 *   percentual negativo. A soma virando denominador faz o regime aceitar
 *   qualquer proporção sem perder a R5.
 *
 * - `CUSTOM` não arredonda nada: as cotas fechadas são o que o morador
 *   escreveu. A última absorve a diferença para o total e, se isso a deixaria
 *   negativa, a recusa é explícita — cota negativa é dívida invertida, e
 *   gravá-la seria pior do que dizer que a regra não fecha.
 *
 * - `EQUAL` é o mesmo caminho proporcional com todos os fatores iguais a um.
 *   Tratar os regimes proporcionais pelo mesmo código é o que impede a soma de
 *   fechar num e estourar no outro.
 *
 * O resíduo da última cota é limitado pelo número de participantes — no máximo
 * `n - 1` centavos — e é sempre para a mesma pessoa: quem a casa cadastrou por
 * último. É o preço de uma regra de fechamento auditável ("a soma é o total, e
 * a diferença está aqui"), e a casa controla o efeito trocando a ordem dos
 * moradores na regra.
 */
final class SplitCalculator implements SplitCalculatorInterface
{
    public function __construct(private readonly MoneyServiceInterface $money) {}

    public function shares(SplitRule $rule, array $entries, string $total): array
    {
        if ($entries === []) {
            throw SplitNotComputable::noParticipants(['split_rule_id' => $rule->id, 'mode' => $rule->mode]);
        }

        $totalCents = $this->money->cents($total);

        $cents = match ($rule->mode) {
            SplitRule::MODE_EQUAL => $this->proportional($totalCents, array_fill(0, count($entries), 100), $rule, 'weight'),
            SplitRule::MODE_WEIGHTED => $this->proportional($totalCents, $this->factors($entries, 'weight'), $rule, 'weight'),
            SplitRule::MODE_PERCENT => $this->proportional($totalCents, $this->factors($entries, 'percent'), $rule, 'percent'),
            SplitRule::MODE_CUSTOM => $this->rebalance($this->cotas($entries), $totalCents),
            default => throw SplitNotComputable::make(
                'unknown_mode',
                'Regra de divisão com regime desconhecido.',
                ['split_rule_id' => $rule->id, 'mode' => $rule->mode],
            ),
        };

        return array_map(
            fn (SplitEntry $entry, int $share): array => [
                'user_id' => (string) $entry->user_id,
                'amount' => $this->money->fromCents($share),
            ],
            $entries,
            $cents,
        );
    }

    /**
     * Fator de cada morador em centésimos.
     *
     * O peso e o percentual também têm duas casas, e é nelas que mora a
     * diferença entre "um terço" e "33,33% de 100,00".
     *
     * @param  list<SplitEntry>  $entries
     * @return list<int>
     */
    private function factors(array $entries, string $field): array
    {
        return array_map(
            fn (SplitEntry $entry): int => $this->money->cents($entry->{$field}),
            $entries,
        );
    }

    /**
     * Cota proporcional aos fatores, com a soma deles no denominador.
     *
     * Normalizar pela soma é o que garante que os truncamentos somem no máximo
     * o total: a diferença residual só pode ser de menos, e é por isso que
     * `rebalance` nunca precisa se preocupar com estouro nos regimes
     * proporcionais.
     *
     * @param  list<int>  $factors
     * @return list<int>
     */
    private function proportional(int $totalCents, array $factors, SplitRule $rule, string $field): array
    {
        $denominator = array_sum($factors);

        if ($denominator === 0) {
            throw SplitNotComputable::zeroTotal($field, [
                'split_rule_id' => $rule->id,
                'mode' => $rule->mode,
            ]);
        }

        $shares = array_map(
            static fn (int $factor): int => intdiv($totalCents * $factor, $denominator),
            $factors,
        );

        return $this->rebalance($shares, $totalCents);
    }

    /**
     * Cota fechada de cada morador, exatamente como foi escrita.
     *
     * @param  list<SplitEntry>  $entries
     * @return list<int>
     */
    private function cotas(array $entries): array
    {
        return array_map(
            fn (SplitEntry $entry): int => $this->money->cents($entry->fixed_amount),
            $entries,
        );
    }

    /**
     * Fecha a soma no total, com o resíduo na última cota (regra R5).
     *
     * @param  list<int>  $shares
     * @return list<int>
     */
    private function rebalance(array $shares, int $totalCents): array
    {
        $last = array_key_last($shares);
        $assigned = 0;

        foreach ($shares as $index => $cents) {
            $assigned += $index === $last ? 0 : $cents;
        }

        $shares[$last] = $totalCents - $assigned;

        if ($shares[$last] < 0) {
            throw SplitNotComputable::exceedsTotal([
                'total' => $this->money->fromCents($totalCents),
                'assigned' => $this->money->fromCents($assigned),
            ]);
        }

        return $shares;
    }
}
