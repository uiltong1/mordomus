<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class MembershipNotFound extends ApiException
{
    public static function make(): self
    {
        return new self(404, 'not_found', 'Membro não pertence à residência.');
    }
}
