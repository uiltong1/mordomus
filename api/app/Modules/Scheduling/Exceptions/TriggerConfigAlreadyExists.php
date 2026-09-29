<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * Já existe regra com o mesmo título para o mesmo alvo — a chave do índice
 * único é (residência, tipo de alvo, alvo, título).
 */
final class TriggerConfigAlreadyExists extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
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
