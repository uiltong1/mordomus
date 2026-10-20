<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;

final class MoneyService implements MoneyServiceInterface
{
    public function cents(?string $amount): int
    {
        if ($amount === null || trim($amount) === '') {
            return 0;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim($amount));

        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        $fraction = substr(str_pad($fraction, 3, '0'), 0, 3);

        return (int) $whole * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);
    }

    public function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public function sum(iterable $amounts): string
    {
        $cents = 0;

        foreach ($amounts as $amount) {
            $cents += $this->cents($amount);
        }

        return $this->fromCents($cents);
    }
}
