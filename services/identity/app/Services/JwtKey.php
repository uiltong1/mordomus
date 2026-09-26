<?php

namespace Mordomus\Identity\Services;

/**
 * Resolve a chave RSA do JWT: o compose injeta o **caminho** do arquivo
 * (/run/secrets/rsa_*.pem), mas firebase/php-jwt espera o PEM em si.
 */
final class JwtKey
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

        // valor já é um PEM, ou é um caminho inexistente (→ considerado ausente)
        return str_contains($value, '-----BEGIN') ? $value : null;
    }
}
