<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class InvitationExpired extends ApiException
{
    public static function make(): self
    {
        return new self(410, 'invitation_expired', 'Convite expirado.');
    }
}
