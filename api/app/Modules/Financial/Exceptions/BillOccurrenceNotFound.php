<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class BillOccurrenceNotFound extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'bill_occurrence_not_found',
            'Vencimento não encontrado na residência ativa.',
            $details,
        );
    }
}
