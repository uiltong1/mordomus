<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class TriggerConfigAlreadyExists extends ApiException
{
    public static function make(array $details = []): self
    {
        return new self(
            409,
            'trigger_config_exists',
            'Já existe uma regra com este título para o mesmo alvo.',
            $details,
        );
    }
}
