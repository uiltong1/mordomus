<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class InvalidCredentials extends ApiException
{
    public static function make(): self
    {
        return new self(401, 'invalid_credentials', 'E-mail ou senha inválidos.');
    }
}
