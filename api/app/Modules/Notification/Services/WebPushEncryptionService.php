<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Mordomus\Notification\Contracts\Services\WebPushEncryptionServiceInterface;
use Mordomus\Notification\Support\PublicKeyPoint;
use RuntimeException;

final class WebPushEncryptionService implements WebPushEncryptionServiceInterface
{
    private const RECORD_SIZE = 4096;

    private const DELIMITER = "\x02";

    public function encrypt(string $plaintext, string $p256dh, string $auth): string
    {
        $receiverKey = $this->base64UrlToBytes($p256dh, 'p256dh');
        $authSecret = $this->base64UrlToBytes($auth, 'auth');

        if (strlen($receiverKey) !== 65) {
            throw new RuntimeException('p256dh da assinatura não é um ponto EC P-256.');
        }

        $ephemeral = $this->ephemeralKey();
        $shared = openssl_pkey_derive(PublicKeyPoint::toPublicKey($receiverKey), $ephemeral['key']);

        if ($shared === false) {
            throw new RuntimeException('Não foi possível derivar o segredo compartilhado da assinatura.');
        }

        $ephemeralPublic = PublicKeyPoint::fromCoordinates($ephemeral['x'], $ephemeral['y']);

        $contentKey = hash_hkdf('sha256', $shared, 16, "Content-Encoding: aes128gcm\0", $authSecret);
        $nonce = hash_hkdf('sha256', $shared, 12, "Content-Encoding: nonce\0", $authSecret);

        $salt = random_bytes(16);
        $header = $salt.pack('N', self::RECORD_SIZE).$ephemeralPublic;

        $tag = '';
        $ciphertext = openssl_encrypt(
            $this->padRecords($plaintext),
            'aes-128-gcm',
            $contentKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $header,
            16,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('A cifra do corpo da notificação falhou.');
        }

        return $this->base64Url($header.$ciphertext.$tag);
    }

    private function padRecords(string $plaintext): string
    {
        $withDelimiter = $plaintext.self::DELIMITER;
        $padding = self::RECORD_SIZE - (strlen($withDelimiter) % self::RECORD_SIZE);

        return $padding === self::RECORD_SIZE
            ? $withDelimiter.str_repeat("\x00", self::RECORD_SIZE - 1).self::DELIMITER
            : $withDelimiter.str_repeat("\x00", $padding);
    }

    private function ephemeralKey(): array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('Não foi possível gerar a chave efêmera do Web Push.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('A chave efêmera do Web Push saiu sem coordenadas EC.');
        }

        return ['key' => $key, 'x' => $details['ec']['x'], 'y' => $details['ec']['y']];
    }

    private function base64UrlToBytes(string $value, string $field): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException(sprintf('O campo `%s` da assinatura não é base64url.', $field));
        }

        return $decoded;
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
