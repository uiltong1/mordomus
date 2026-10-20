<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class SplitRuleNotFound extends ApiException
{
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'split_rule_not_found',
            'Esta conta não tem regra de divisão ativa na residência.',
            $details,
        );
    }
}
