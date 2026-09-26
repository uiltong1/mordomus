<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\JwtIssuer;
use Mordomus\Identity\Services\JwtVerifier;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->syncServerEnv();

        parent::setUp();

        $this->ensureJwtKeys();
    }

    /**
     * O PHPUnit grava os `<env>` do phpunit.xml em $_ENV/getenv, mas não em
     * $_SERVER — e o repositório de env do Laravel lê $_SERVER primeiro (onde
     * seguem as variáveis do compose, ex.: DB_CONNECTION=pgsql). Copia $_ENV
     * para $_SERVER antes do boot para que os valores de teste valam de fato.
     */
    private function syncServerEnv(): void
    {
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $_SERVER[$key] = $value;
            }
        }
    }

    /**
     * Sem chaves no ambiente (ex.: execução fora do compose) o teste gera um par
     * efêmero — os testes ficam autocontidos.
     */
    private function ensureJwtKeys(): void
    {
        if (config('jwt.private_key') && config('jwt.public_key')) {
            return;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);
        $publicKey = openssl_pkey_get_details($resource)['key'];

        config([
            'jwt.private_key' => $privateKey,
            'jwt.public_key' => $publicKey,
            'jwt.kid' => 'test-kid',
        ]);
    }

    /**
     * Header Authorization com um JWT RS256 real para o usuário/tenant.
     *
     * @return array<string, string>
     */
    protected function authHeadersFor(User $user, ?string $tenantId = null): array
    {
        $tokens = app(JwtIssuer::class)->issue($user, $tenantId);

        return [
            'Authorization' => 'Bearer '.$tokens['access_token'],
            'Accept' => 'application/json',
        ];
    }

    protected function asUser(User $user, ?string $tenantId = null): static
    {
        return $this->withHeaders($this->authHeadersFor($user, $tenantId));
    }

    /** @return array<string, mixed> claims decodificados */
    protected function claimsOf(string $token): array
    {
        return app(JwtVerifier::class)->verify($token);
    }
}
