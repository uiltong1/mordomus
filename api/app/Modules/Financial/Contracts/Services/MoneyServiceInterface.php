<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

interface MoneyServiceInterface
{
    public function cents(?string $amount): int;

    public function fromCents(int $cents): string;

    public function sum(iterable $amounts): string;
}
