<?php

namespace Mordomus\Identity\Services;

/**
 * Token de convite: valor na URL, apenas o hash persiste.
 */
class InvitationTokenService
{
    public const TTL_DAYS = 7;

    /**
     * @return array{plain: string, hash: string}
     */
    public static function generate(): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['plain' => $plain, 'hash' => self::hash($plain)];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function expiresAt(): \DateTimeInterface
    {
        return now()->addDays(self::TTL_DAYS);
    }
}
