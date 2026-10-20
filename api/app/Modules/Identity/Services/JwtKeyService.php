<?php

namespace Mordomus\Identity\Services;

final class JwtKeyService
{
    public static function resolve(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (is_file($value)) {
            $contents = file_get_contents($value);

            return $contents === false ? null : $contents;
        }

        return str_contains($value, '-----BEGIN') ? $value : null;
    }
}
