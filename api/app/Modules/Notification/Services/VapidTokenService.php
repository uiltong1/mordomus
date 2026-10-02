<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Mordomus\Notification\Contracts\Services\VapidTokenServiceInterface;
use Mordomus\Notification\Support\PublicKeyPoint;
use RuntimeException;

/**
 * Credencial VAPID: o par EC P-256 do ambiente e o JWT que autentica o envio.
 *
 * O `aud` é a **origem** do serviço de push que vai receber a mensagem, e não
 * a do endpoint: é a origem que o serviço valida, e mandá-la diferente é o que
 * faz o serviço recusar a mensagem com 401.
 *
 * A validade é curta de propósito. O token viaja em toda requisição de push e
 * qualquer um que o intercepte consegue mandar mensagem para as assinaturas
 * daquele par; 12 h é o teto do protocolo e um aviso de casa não justifica
 * mais do que isso.
 */
final class VapidTokenService implements VapidTokenServiceInterface
{
    /** Teto do protocolo para a validade do token de autenticação. */
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

        // O `k` do cabeçalho é o ponto não comprimido (RFC 8292): 0x04 || X || Y.
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

    /**
     * O compose injeta o **caminho** do arquivo, como faz com o par do JWT; o
     * que vier com `BEGIN` já é o PEM em si.
     */
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
