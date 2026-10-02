<?php

declare(strict_types=1);

namespace Mordomus\Notification\Support;

/**
 * Ponto público EC P-256 no formato que o Web Push usa.
 *
 * Existe por um motivo concreto e intermitente: o `openssl_pkey_get_details`
 * devolve as coordenadas X e Y com 32 bytes **na maioria das vezes** e com 33
 * de vez em quando, quando o primeiro byte é `0x00` de um inteiro positivo que
 * o openssl representa com sinal. O ponto não comprimido tem 65 bytes
 * invariantes (`0x04 || X(32) || Y(32)`), e um byte a mais ali produz um
 * `SubjectPublicKeyInfo` malformado — que o navegador do outro lado recusa com
 * um erro que não aponta para a cifra.
 *
 * Normalizar em um lugar só é o que evita a mesma armadilha em dois sítios: a
 * chave efêmera de cada mensagem e a chave pública VAPID do ambiente.
 */
final class PublicKeyPoint
{
    /** Comprimento fixo de cada coordenada em P-256. */
    private const COORDINATE_SIZE = 32;

    /**
     * Ponto não comprimido a partir das coordenadas do openssl.
     *
     * @return string 65 bytes
     */
    public static function fromCoordinates(string $x, string $y): string
    {
        return "\x04".self::coordinate($x).self::coordinate($y);
    }

    /**
     * PEM que o `openssl_pkey_get_public` entende.
     *
     * O openssl não lê DER cru, e a assinatura do navegador chega como ponto
     * cru: o caminho é embrulhar o ponto num DER e apresentá-lo em PEM. O DER é
     * montado por partes nomeadas em vez de um hexadecimal corrido porque o
     * comprimento total é a soma delas, e um prefixo escrito à mão erra em um
     * byte sem o openssl explicar por quê.
     *
     * @return \OpenSSLAsymmetricKey
     */
    public static function toPublicKey(string $point)
    {
        // id-ecPublicKey (1.2.840.10045.2.1) e prime256v1 (1.2.840.10045.3.1.7)
        $algorithm = "\x30\x13"
            ."\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
            ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

        // BIT STRING de 66 bytes, com zero bits não usados na cabeça
        $der = "\x30\x59".$algorithm."\x03\x42\x00".$point;

        $key = openssl_pkey_get_public(
            "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n",
        );

        if ($key === false) {
            throw new \RuntimeException('A chave pública EC informada não é um ponto P-256 válido.');
        }

        return $key;
    }

    /**
     * A coordenada em exatamente 32 bytes.
     *
     * Sobra à esquerda some (o byte `0x00` de sinal não faz parte do número) e
     * falta à esquerda ganha zero, que é o valor que ele representa.
     */
    private static function coordinate(string $raw): string
    {
        return str_pad(substr($raw, -self::COORDINATE_SIZE), self::COORDINATE_SIZE, "\x00", STR_PAD_LEFT);
    }
}
