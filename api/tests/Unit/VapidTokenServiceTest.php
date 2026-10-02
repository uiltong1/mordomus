<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mordomus\Notification\Services\VapidTokenService;
use Mordomus\Notification\Support\PublicKeyPoint;
use RuntimeException;
use Tests\TestCase;

/**
 * Credencial VAPID: o par do ambiente e o JWT que autentica o envio.
 */
class VapidTokenServiceTest extends TestCase
{
    private VapidTokenService $vapid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notification.vapid' => [
            'public_key' => null,
            'private_key' => null,
            'subject' => 'mailto:suporte@mordomus.app',
        ]]);

        $this->vapid = new VapidTokenService;
    }

    public function test_an_environment_without_the_pair_is_not_configured(): void
    {
        $this->assertFalse($this->vapid->isConfigured());
    }

    public function test_the_public_key_is_the_uncompressed_point_the_browser_signs(): void
    {
        $this->withPair();

        $this->assertTrue($this->vapid->isConfigured());

        $point = base64_decode(strtr($this->vapid->publicKey(), '-_', '+/'), true);

        // O `applicationServerKey` do `pushManager.subscribe()` é o ponto não
        // comprimido: 0x04 || X(32) || Y(32), 65 bytes invariantes.
        $this->assertSame(65, strlen($point));
        $this->assertSame(0x04, ord($point[0]));
    }

    public function test_the_public_key_opens_the_pair_of_the_environment(): void
    {
        $details = $this->withPair();

        // A comparação é pela identidade da chave e não pelas coordenadas
        // cruas: o openssl devolve uma coordenada com um byte a mais de vez em
        // quando, e comparar byte a byte daria um teste que falha sozinho.
        $fromPoint = openssl_pkey_get_details(
            PublicKeyPoint::toPublicKey(base64_decode(strtr($this->vapid->publicKey(), '-_', '+/'), true)),
        );

        $this->assertIsArray($fromPoint);
        $this->assertSame(openssl_pkey_get_details(openssl_pkey_get_public($details['key']))['key'], $fromPoint['key']);
    }

    public function test_the_token_carries_the_origin_the_service_validates(): void
    {
        $this->withPair();

        $claims = $this->claimsOfToken($this->vapid->token('https://fcm.googleapis.com'));

        $this->assertSame('https://fcm.googleapis.com', $claims->aud);
        $this->assertSame('mailto:suporte@mordomus.app', $claims->sub);
    }

    public function test_the_token_expires_within_the_ceiling_of_the_protocol(): void
    {
        $this->withPair();

        $claims = $this->claimsOfToken($this->vapid->token('https://fcm.googleapis.com'));

        // O token viaja em toda requisição de push: quem o intercepta manda
        // mensagem para as assinaturas daquele par. 12 h é o teto da RFC 8292.
        $this->assertLessThanOrEqual(12 * 3600, $claims->exp - time());
        $this->assertGreaterThan(11 * 3600, $claims->exp - time());
    }

    public function test_the_token_is_signed_with_es256(): void
    {
        $this->withPair();

        $token = $this->vapid->token('https://fcm.googleapis.com');
        $header = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true), true);

        $this->assertSame('ES256', $header['alg']);
    }

    public function test_reading_the_public_key_without_a_pair_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);

        $this->vapid->publicKey();
    }

    public function test_signing_without_a_pair_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);

        $this->vapid->token('https://fcm.googleapis.com');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function withPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);

        config(['notification.vapid' => [
            'public_key' => $details['key'],
            'private_key' => $privatePem,
            'subject' => 'mailto:suporte@mordomus.app',
        ]]);

        return $details;
    }

    private function claimsOfToken(string $token): object
    {
        $payload = json_decode(
            (string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true),
        );

        $this->assertIsObject($payload);

        return $payload;
    }
}
