<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Mordomus\Financial\Models\SplitRule;

interface SplitCalculatorInterface
{
    public function shares(SplitRule $rule, array $entries, string $total): array;
}
