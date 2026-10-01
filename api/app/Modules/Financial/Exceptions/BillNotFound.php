<?php

declare(strict_types=1);

namespace Mordomus\Financial\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class BillNotFound extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'bill_not_found',
            'Conta não encontrada na residência ativa.',
            $details,
        );
    }
}
