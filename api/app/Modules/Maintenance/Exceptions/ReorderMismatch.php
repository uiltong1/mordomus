<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class ReorderMismatch extends ApiException
{
    /**
     * @param  list<string>  $expected
     * @param  list<string>  $received
     */
    public static function make(array $expected, array $received): self
    {
        return new self(
            422,
            'validation_failed',
            'A lista de ids precisa ser exatamente os cômodos não arquivados da residência.',
            ['expected' => $expected, 'received' => $received],
        );
    }
}
