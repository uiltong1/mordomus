<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;

/**
 * Dinheiro em centavos, de entrada e de saída.
 *
 * O texto decimal entra sempre pelo mesmo caminho: a entrada canônica é
 * `1234.56`, e a conversão passa por inteiro porque é o único tipo em que
 * centavo não perde meio centavo. Somar em ponto flutuante e arredondar no fim
 * fecharia a conta na maioria dos casos e falharia justamente nos centavos que a
 * regra R5 exige.
 */
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

        // A terceira casa arredonda para o centavo: o valor gravado tem duas, e
        // o resto é ruído de quem digitou.
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
