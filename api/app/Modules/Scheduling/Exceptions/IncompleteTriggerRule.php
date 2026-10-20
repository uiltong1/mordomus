<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class IncompleteTriggerRule extends ApiException
{
    public static function make(string $type, array $missing, array $details = []): self
    {
        return new self(
            422,
            'incomplete_trigger_rule',
            'Regra de recorrência incompleta para o tipo informado.',
            $details + ['type' => $type, 'missing' => $missing],
        );
    }
}
