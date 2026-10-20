<?php

declare(strict_types=1);

namespace Mordomus\Notification\Support;

final class PublicKeyPoint
{
    private const COORDINATE_SIZE = 32;

    public static function fromCoordinates(string $x, string $y): string
    {
        return "\x04".self::coordinate($x).self::coordinate($y);
    }

    public static function toPublicKey(string $point)
    {

        $algorithm = "\x30\x13"
            ."\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
            ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

        $der = "\x30\x59".$algorithm."\x03\x42\x00".$point;

        $key = openssl_pkey_get_public(
            "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n",
        );

        if ($key === false) {
            throw new \RuntimeException('A chave pública EC informada não é um ponto P-256 válido.');
        }

        return $key;
    }

    private static function coordinate(string $raw): string
    {
        return str_pad(substr($raw, -self::COORDINATE_SIZE), self::COORDINATE_SIZE, "\x00", STR_PAD_LEFT);
    }
}
