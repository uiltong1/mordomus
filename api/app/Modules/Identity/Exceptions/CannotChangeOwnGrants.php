<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class CannotChangeOwnGrants extends ApiException
{
    public static function make(): self
    {
        return new self(422, 'cannot_change_own_grants', 'Não é possível alterar os próprios grants.');
    }
}
