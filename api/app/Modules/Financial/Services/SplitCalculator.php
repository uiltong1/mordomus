<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitCalculatorInterface;
use Mordomus\Financial\Exceptions\SplitNotComputable;
use Mordomus\Financial\Models\SplitEntry;
use Mordomus\Financial\Models\SplitRule;

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

    private function factors(array $entries, string $field): array
    {
        return array_map(
            fn (SplitEntry $entry): int => $this->money->cents($entry->{$field}),
            $entries,
        );
    }

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

    private function cotas(array $entries): array
    {
        return array_map(
            fn (SplitEntry $entry): int => $this->money->cents($entry->fixed_amount),
            $entries,
        );
    }

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
