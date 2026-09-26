<?php

namespace Mordomus\Identity\Services;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Verificação do JWT de usuário (RS256).
 * Lança exceções; o guard converte qualquer falha em "não autenticado".
 */
class JwtVerifier
{
    /**
     * @return array<string, mixed> claims decodificados
     *
     * @throws BeforeValidException
     * @throws ExpiredException
     * @throws SignatureInvalidException
     * @throws \UnexpectedValueException
     */
    public function verify(string $token): array
    {
        $publicKey = config('jwt.public_key');
        if (! $publicKey) {
            throw new \RuntimeException('JWT_RSA_PUBLIC_KEY ausente — rode "make secrets" e suba via compose.');
        }

        JWT::$leeway = (int) config('jwt.leeway', 30);

        $decoded = JWT::decode($token, new Key($publicKey, (string) config('jwt.algorithm', 'RS256')));
        $claims = (array) $decoded;

        $issuer = config('jwt.issuer');
        if (($claims['iss'] ?? null) !== $issuer) {
            throw new \UnexpectedValueException("issuer inválido (esperado: {$issuer})");
        }

        if (empty($claims['sub'])) {
            throw new \UnexpectedValueException('claim sub ausente');
        }

        return $claims;
    }
}
