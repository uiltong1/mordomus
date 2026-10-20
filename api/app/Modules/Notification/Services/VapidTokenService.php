<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Mordomus\Notification\Contracts\Services\VapidTokenServiceInterface;
use Mordomus\Notification\Support\PublicKeyPoint;
use RuntimeException;

final class VapidTokenService implements VapidTokenServiceInterface
{
    private const MAX_TTL_HOURS = 12;

    public function isConfigured(): bool
    {
        return $this->privateKey() !== null && $this->configuredPublicKey() !== null;
    }

    public function publicKey(): string
    {
        $key = $this->configuredPublicKey();

        if ($key === null) {
            throw new RuntimeException('VAPID sem chave pública — rode "make secrets" e suba via compose.');
        }

        $public = openssl_pkey_get_public($key);

        if ($public === false) {
            throw new RuntimeException('VAPID_PUBLIC_KEY não é uma chave EC P-256 válida.');
        }

        $details = openssl_pkey_get_details($public);

        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('VAPID_PUBLIC_KEY não é uma chave EC P-256 válida.');
        }

        return $this->base64Url(PublicKeyPoint::fromCoordinates($details['ec']['x'], $details['ec']['y']));
    }

    public function token(string $audience): string
    {
        $privateKey = $this->privateKey();

        if ($privateKey === null) {
            throw new RuntimeException('VAPID sem chave privada — rode "make secrets" e suba via compose.');
        }

        $now = CarbonImmutable::now('UTC');

        return JWT::encode([
            'aud' => $audience,
            'exp' => $now->addHours(self::MAX_TTL_HOURS)->getTimestamp(),
            'sub' => (string) config('notification.vapid.subject'),
        ], $privateKey, 'ES256');
    }

    private function privateKey(): ?string
    {
        return $this->pem(config('notification.vapid.private_key'));
    }

    private function configuredPublicKey(): ?string
    {
        return $this->pem(config('notification.vapid.public_key'));
    }

    private function pem(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (is_file($value)) {
            $contents = file_get_contents($value);

            return $contents === false ? null : $contents;
        }

        return str_contains($value, '-----BEGIN') ? $value : null;
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
