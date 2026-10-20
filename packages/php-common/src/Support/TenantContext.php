<?php

namespace Mordomus\Common\Support;

final class TenantContext
{
    private static ?string $tenantId = null;

    private static ?string $userId = null;

    private static ?array $claims = null;

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

    public static function claims(): ?array
    {
        return self::$claims;
    }

    public static function has(): bool
    {
        return self::$tenantId !== null;
    }

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
