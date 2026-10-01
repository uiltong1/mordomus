<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

/**
 * Aritmética de dinheiro da casa, em centavos.
 *
 * Separada porque é a única parte do módulo que não depende de banco nem de
 * fuso: soma em ponto flutuante de centavo não fecha, e o critério do split
 * (regra R5) é exatamente a soma bater com o total.
 */
interface MoneyServiceInterface
{
    /** Texto decimal (`1200.00`) para centavos (`120000`). */
    public function cents(?string $amount): int;

    /** Centavos para texto decimal com duas casas. */
    public function fromCents(int $cents): string;

    /** @param iterable<string|null> $amounts */
    public function sum(iterable $amounts): string;
}
