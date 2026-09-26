<?php

declare(strict_types=1);

namespace Mordomus\Identity\Exceptions;

use Mordomus\Http\Exceptions\ApiException;

/**
 * Falha na troca do refresh token → 401.
 */
class InvalidRefreshToken extends ApiException
{
    public const CODE_UNKNOWN = 'invalid_refresh_token';

    public const CODE_EXPIRED = 'refresh_token_expired';

    public const CODE_REUSED = 'refresh_token_reused';

    public static function unknown(): self
    {
        return new self(401, self::CODE_UNKNOWN, 'Refresh token inválido.');
    }

    public static function expired(): self
    {
        return new self(401, self::CODE_EXPIRED, 'Refresh token expirado.');
    }

    public static function reused(): self
    {
        return new self(401, self::CODE_REUSED, 'Refresh token já utilizado — sessões revogadas por segurança.');
    }
}
