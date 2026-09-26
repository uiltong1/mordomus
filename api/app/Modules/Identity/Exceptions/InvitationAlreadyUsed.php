<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class InvitationAlreadyUsed extends ApiException
{
    public static function make(): self
    {
        return new self(409, 'invitation_already_used', 'Convite já utilizado.');
    }
}
