<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class OccurrenceNotFound extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'occurrence_not_found',
            'Ocorrência não encontrada na residência ativa.',
            $details,
        );
    }
}
