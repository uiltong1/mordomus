<?php

declare(strict_types=1);

namespace Mordomus\Http\Exceptions;

final class Forbidden extends ApiException
{
    public static function make(): self
    {
        return new self(403, 'forbidden', 'forbidden');
    }
}
