<?php

namespace Mordomus\Identity\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Mordomus\Identity\Contracts\Services\JwtVerifierServiceInterface;

class JwtVerifierService implements JwtVerifierServiceInterface
{
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
