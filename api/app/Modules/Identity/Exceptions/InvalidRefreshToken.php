<?php

namespace Mordomus\Identity\Exceptions;

/**
 * Falha na troca do refresh token → 401.
 */
class InvalidRefreshToken extends \RuntimeException
{
    public const CODE_UNKNOWN = 'invalid_refresh_token';

    public const CODE_EXPIRED = 'refresh_token_expired';

    public const CODE_REUSED = 'refresh_token_reused';

    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function unknown(): self
    {
        return new self(self::CODE_UNKNOWN, 'Refresh token inválido.');
    }

    public static function expired(): self
    {
        return new self(self::CODE_EXPIRED, 'Refresh token expirado.');
    }

    public static function reused(): self
    {
        return new self(self::CODE_REUSED, 'Refresh token já utilizado — sessões revogadas por segurança.');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
