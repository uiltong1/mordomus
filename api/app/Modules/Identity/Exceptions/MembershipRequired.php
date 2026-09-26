<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class MembershipRequired extends ApiException
{
    public static function make(string $message = 'Sem acesso à residência.'): self
    {
        return new self(403, 'membership_required', $message);
    }
}
