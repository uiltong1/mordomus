<?php

namespace Mordomus\Common\Support;

/**
 * Contexto de tenant da requisição corrente (regra R1).
 *
 * Alimentado pelo middleware TenantScope a partir do JWT de serviço — nunca
 * do corpo da requisição — e lido pelo escopo global do Eloquent.
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    private static ?string $userId = null;

    /** @var array<string, mixed>|null */
    private static ?array $claims = null;

    /**
     * @param  array<string, mixed>|null  $claims
     */
    public static function set(string $tenantId, ?string $userId = null, ?array $claims = null): void
    {
        self::$tenantId = $tenantId;
        self::$userId = $userId;
        self::$claims = $claims;
    }

    public static function clear(): void
    {
        self::$tenantId = null;
        self::$userId = null;
        self::$claims = null;
    }

    public static function tenantId(): ?string
    {
        return self::$tenantId;
    }

    public static function userId(): ?string
    {
        return self::$userId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function claims(): ?array
    {
        return self::$claims;
    }

    public static function has(): bool
    {
        return self::$tenantId !== null;
    }

    /**
     * Executa um callback com o tenant informado e restaura o anterior.
     *
     * @template TRet
     *
     * @param  callable(): TRet  $callback
     * @return TRet
     */
    public static function runWith(string $tenantId, callable $callback): mixed
    {
        $previous = [self::$tenantId, self::$userId, self::$claims];

        self::set($tenantId);

        try {
            return $callback();
        } finally {
            [self::$tenantId, self::$userId, self::$claims] = $previous;
        }
    }
}
