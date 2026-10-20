<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class OccurrenceAlreadyFinalized extends ApiException
{
    public static function make(string $status, string $operation): self
    {
        return new self(
            409,
            'occurrence_already_finalized',
            'A ocorrência já foi encerrada e não aceita esta operação.',
            ['status' => $status, 'operation' => $operation],
        );
    }
}
