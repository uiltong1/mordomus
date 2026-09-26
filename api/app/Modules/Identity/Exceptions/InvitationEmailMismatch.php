<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class InvitationEmailMismatch extends ApiException
{
    public static function make(): self
    {
        return new self(403, 'invitation_email_mismatch', 'O convite pertence a outro e-mail.');
    }
}
