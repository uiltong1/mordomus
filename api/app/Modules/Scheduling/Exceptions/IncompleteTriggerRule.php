<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * A regra guardada não tem os campos que o próprio `type` exige, então não há
 * data a calcular. Aponta os campos faltantes para a regra poder ser corrigida.
 */
final class IncompleteTriggerRule extends ApiException
{
    /**
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $details
     */
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
