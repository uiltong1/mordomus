<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class TriggerConfigNotFound extends ApiException
{
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'trigger_config_not_found',
            'Regra de recorrência não encontrada na residência ativa.',
            $details,
        );
    }
}
