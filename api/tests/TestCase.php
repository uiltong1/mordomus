<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\JwtIssuerService;
use Mordomus\Identity\Services\JwtVerifierService;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->syncServerEnv();

        parent::setUp();

        $this->ensureJwtKeys();
    }

    private function syncServerEnv(): void
    {
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $_SERVER[$key] = $value;
            }
        }
    }

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

    protected function authHeadersFor(User $user, ?string $tenantId = null): array
    {
        $tokens = app(JwtIssuerService::class)->issue($user, $tenantId);

        return [
            'Authorization' => 'Bearer '.$tokens['access_token'],
            'Accept' => 'application/json',
        ];
    }

    protected function asUser(User $user, ?string $tenantId = null): static
    {
        return $this->withHeaders($this->authHeadersFor($user, $tenantId));
    }

    protected function claimsOf(string $token): array
    {
        return app(JwtVerifierService::class)->verify($token);
    }
}
