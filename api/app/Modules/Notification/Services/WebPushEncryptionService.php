<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Mordomus\Notification\Contracts\Services\WebPushEncryptionServiceInterface;
use Mordomus\Notification\Support\PublicKeyPoint;
use RuntimeException;

/**
 * Cifra o corpo da notificação para uma assinatura de Web Push (RFC 8291).
 *
 * Sem isto o corpo iria em claro para o serviço de push, que é um terceiro
 * que o morador não escolheu. O esquema é `aes128gcm`: chave de conteúdo e
 * nonce saem do segredo de autenticação do assinante combinado com um ECDH
 * efêmero, e a chave pública efêmera vai no cabeçalho do corpo — é o que
 * permite ao navegador recuperar o conteúdo.
 *
 * A senha da chave (`PRK`) é o segredo de autenticação da assinatura e o
 * material do ECDH; as três derivações saem do mesmo par, e é por isso que
 * trocar `p256dh` ou `auth` na mesma linha sem refazer a assinatura produziria
 * um corpo que o navegador não abre.
 *
 * Tudo aqui é inteiro em memória e nada é gravado: a chave efêmera morre com a
 * requisição, que é o que a RFC pede.
 */
final class WebPushEncryptionService implements WebPushEncryptionServiceInterface
{
    /** Tamanho do registro cifrado, em bytes. */
    private const RECORD_SIZE = 4096;

    /** Delimitador de fim de registro com "sem padding" (RFC 8188). */
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

        // A chave de conteúdo e o nonce saem do mesmo segredo, com contextos
        // (`info`) diferentes — é o que impede um nonce de servir para outra
        // mensagem.
        // O segredo de autenticação da assinatura entra como *salt* do HKDF, e
        // é isso que a RFC quer: a senha da chave (`PRK`) é
        // `HMAC(auth_secret, segredo_ecdh)`, e o `hash_hkdf` faz exatamente
        // isso ao receber o segredo de autenticação como salt.
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

    /**
     * Fecha o corpo em registros de tamanho fixo (RFC 8188).
     *
     * O último registro é `dados | delimitador | zeros`, e é o **zeros depois
     * do delimitador** que importa: o navegador lê o último registro de trás
     * para frente, pula os zeros e acha o `0x02`, que é a marca de fim. O
     * delimitador é a flag, e não a contagem do padding — um registro pode
     * precisar de até 4095 bytes de enchimento, e um byte só não conta isso.
     *
     * Quando os dados já fecham um registro exato, o delimitador não cabe e o
     * protocolo exige um registro final de padding: sem essa linha, o
     * navegador lê a mensagem como incompleta e não mostra nada.
     */
    private function padRecords(string $plaintext): string
    {
        $withDelimiter = $plaintext.self::DELIMITER;
        $padding = self::RECORD_SIZE - (strlen($withDelimiter) % self::RECORD_SIZE);

        return $padding === self::RECORD_SIZE
            ? $withDelimiter.str_repeat("\x00", self::RECORD_SIZE - 1).self::DELIMITER
            : $withDelimiter.str_repeat("\x00", $padding);
    }

    /** @return array{key: \OpenSSLAsymmetricKey, x: string, y: string} */
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
