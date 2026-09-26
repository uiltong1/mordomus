<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class CannotChangeOwnRole extends ApiException
{
    public static function make(): self
    {
        return new self(422, 'cannot_change_own_role', 'Não é possível alterar a própria role.');
    }
}
