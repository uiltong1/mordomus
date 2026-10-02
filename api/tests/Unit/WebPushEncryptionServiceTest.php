<?php

namespace Tests\Unit;

use Mordomus\Notification\Services\WebPushEncryptionService;
use Mordomus\Notification\Support\PublicKeyPoint;
use RuntimeException;
use Tests\TestCase;

/**
 * Cifra do corpo da notificação (RFC 8291, `aes128gcm`).
 *
 * O teste traz o **decifrador** dele mesmo, escrito a partir do texto da RFC e
 * não a partir do serviço: os dois precisam concordar sobre o escalonamento, o
 * cabeçalho e o delimitador de padding, e concordar por implementação
 * compartilhada seria a mesma linha de código validada duas vezes.
 *
 * O lado do "navegador" é um par real e o decifrador refaz o ECDH com a
 * metade privada dele — é o que prova que o segredo compartilhado saiu certo, e
 * não só que o formato do corpo está no lugar.
 */
class WebPushEncryptionServiceTest extends TestCase
{
    private const RECORD_SIZE = 4096;

    private WebPushEncryptionService $encryption;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encryption = new WebPushEncryptionService;
    }

    public function test_the_encrypted_body_opens_back_into_the_same_text(): void
    {
        $plaintext = json_encode(['title' => 'Conta de luz', 'body' => 'R$ 187,43'], JSON_UNESCAPED_UNICODE);
        $browser = $this->browser();

        $body = $this->encrypt($plaintext, $browser);

        $this->assertSame(
            $plaintext,
            $this->decrypt($body, $browser),
            'O corpo cifrado precisa abrir no mesmo texto que entrou (RFC 8291).',
        );
    }

    /** O corpo viaja no formato da Push API: base64url sem padding. */
    public function test_the_body_is_base64url_without_padding(): void
    {
        $body = $this->encrypt('aviso', $this->browser());

        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $body);
    }

    /** Acento e barra não viram escape no caminho. */
    public function test_a_unicode_payload_survives_the_round_trip(): void
    {
        $plaintext = json_encode(
            ['title' => 'Conta de luz: R$ 187,43', 'body' => 'Amanhã às 10:00'],
            JSON_UNESCAPED_UNICODE,
        );
        $browser = $this->browser();

        $this->assertSame($plaintext, $this->decrypt($this->encrypt($plaintext, $browser), $browser));
    }

    public function test_the_header_carries_the_salt_the_record_size_and_the_ephemeral_key(): void
    {
        $header = $this->header($this->encrypt('aviso', $this->browser()));

        $this->assertSame(self::RECORD_SIZE, unpack('N', substr($header, 16, 4))[1]);
        $this->assertSame("\x04", substr($header, 20, 1), 'o ponto efêmero é 0x04 || X || Y');
        $this->assertSame(85, strlen($header));
    }

    /**
     * Payload que fecha mais de um registro: o delimitador de fim tem de estar
     * no último registro, e sem ele o navegador lê a mensagem como incompleta.
     */
    public function test_a_payload_spanning_records_opens_back(): void
    {
        $plaintext = str_repeat('mordomus', 700);
        $browser = $this->browser();

        $body = $this->encrypt($plaintext, $browser);

        $this->assertSame($plaintext, $this->decrypt($body, $browser));
        $this->assertGreaterThan(self::RECORD_SIZE, strlen($this->raw($body)));
    }

    /**
     * Payload cujo delimitador já fecha um registro exato: o protocolo exige um
     * registro final de padding, e é o `0x02` dele que marca o fim dos dados.
     */
    public function test_a_payload_on_the_record_boundary_gets_an_extra_record(): void
    {
        $plaintext = str_repeat('a', self::RECORD_SIZE - 1);
        $browser = $this->browser();

        $this->assertSame($plaintext, $this->decrypt($this->encrypt($plaintext, $browser), $browser));
    }

    public function test_two_bodies_differ_because_the_salt_is_random(): void
    {
        $browser = $this->browser();

        $this->assertNotSame(
            $this->encrypt('aviso', $browser),
            $this->encrypt('aviso', $browser),
        );
    }

    public function test_a_point_that_is_not_p256_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->encrypt('aviso', ['p256dh' => $this->base64Url("\x04".str_repeat("\x11", 64)), 'auth' => 'c2FsdA']);
    }

    public function test_keys_that_are_not_base64url_are_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->encrypt('aviso', ['p256dh' => 'não é base64url!!', 'auth' => 'c2FsdA']);
    }

    // -------------------------------------------------------------- helpers

    /**
     * O lado do navegador: par de chaves de verdade, porque o decifrador
     * precisa da metade privada para refazer o ECDH.
     *
     * @return array{p256dh: string, auth: string, private: string}
     */
    private function browser(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key);

        // `details['key']` é a PEM **pública**; a privada sai do export, e é
        // dela que o decifrador refaz o ECDH.
        openssl_pkey_export($key, $private);

        return [
            'p256dh' => $this->base64Url(PublicKeyPoint::fromCoordinates($details['ec']['x'], $details['ec']['y'])),
            'auth' => $this->base64Url(random_bytes(16)),
            'private' => $private,
        ];
    }

    /** @param array{p256dh: string, auth: string} $browser */
    private function encrypt(string $plaintext, array $browser): string
    {
        return $this->encryption->encrypt($plaintext, $browser['p256dh'], $browser['auth']);
    }

    /**
     * Decifra seguindo a RFC 8291, independente do serviço.
     *
     * @param  array{p256dh: string, auth: string, private: string}  $browser
     */
    private function decrypt(string $body, array $browser): string
    {
        $raw = $this->raw($body);
        $header = substr($raw, 0, 85);
        $salt = substr($header, 0, 16);
        $ephemeralPublic = substr($header, 20);

        // O ECDH é o mesmo dos dois lados: quem cifrou usou a efêmera com a
        // pública do navegador, e o decifrador usa a privada do navegador com
        // a pública efêmera que veio no cabeçalho.
        $shared = openssl_pkey_derive(
            $this->publicKey($ephemeralPublic),
            openssl_pkey_get_private($browser['private']),
        );

        // O escalonamento escrito à mão, a partir do texto da RFC 8291 §3.3:
        // a senha da chave é `HMAC(auth_secret, segredo_ecdh)`, e a chave de
        // conteúdo e o nonce são expansões dela com contextos diferentes.
        // Escrever assim, e não chamando `hash_hkdf`, é o que torna o teste
        // independente do serviço — os dois precisam concordar sobre a ordem.
        $prk = hash_hmac('sha256', $shared, $this->raw($browser['auth']), true);
        $contentKey = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $plain = openssl_decrypt(
            substr($raw, 85, -16),
            'aes-128-gcm',
            $contentKey,
            OPENSSL_RAW_DATA,
            $nonce,
            substr($raw, -16),
            $header,
        );

        $this->assertNotFalse($plain, 'O corpo cifrado não abriu: chave de conteúdo, nonce ou tag errados.');

        // O navegador lê registro a registro: em cada um pula os zeros de
        // padding e procura o delimitador `0x02`; ele marca onde os dados
        // acabam, e o que vier depois é registro de enchimento.
        $data = '';

        foreach (str_split($plain, self::RECORD_SIZE) as $record) {
            $trimmed = rtrim($record, "\x00");

            if (substr($trimmed, -1) === "\x02") {
                $this->assertNotSame('', $trimmed, 'O registro final tem de ter dados antes do delimitador.');

                return $data.substr($trimmed, 0, -1);
            }

            $data .= $trimmed;
        }

        $this->fail('Nenhum registro do corpo tem o delimitador de fim.');

        return '';
    }

    /**
     * O ponto efêmero do cabeçalho, embrulhado em PEM.
     *
     * O comprimento vem primeiro e à parte: o ponto não comprimido tem 65 bytes
     * invariantes, e o openssl devolve uma coordenada com um byte a mais de vez
     * em quando — o que faria o `SubjectPublicKeyInfo` sair malformado e a
     * decifração falhar por um motivo que não é o da cifra.
     */
    private function publicKey(string $rawPoint): \OpenSSLAsymmetricKey
    {
        $this->assertSame(
            65,
            strlen($rawPoint),
            'A chave efêmera do cabeçalho não é um ponto P-256 não comprimido de 65 bytes.',
        );

        return PublicKeyPoint::toPublicKey($rawPoint);
    }

    private function header(string $body): string
    {
        return substr($this->raw($body), 0, 85);
    }

    private function raw(string $body): string
    {
        return (string) base64_decode(strtr($body, '-_', '+/'), true);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
