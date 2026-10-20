<?php

namespace Mordomus\Notification\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

final class PushNotConfigured extends ApiException
{
    public static function make(): self
    {
        return new self(
            503,
            'push_not_configured',
            'Este ambiente ainda não tem chave de push configurada (VAPID_PUBLIC_KEY).',
        );
    }
}
