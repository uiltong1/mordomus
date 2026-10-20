<?php

namespace Mordomus\Notification\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class DeviceTokenNotFound extends ApiException
{
    public static function make(array $details = []): self
    {
        return new self(
            404,
            'device_token_not_found',
            'Esta assinatura de push não está registrada para você.',
            $details,
        );
    }
}
