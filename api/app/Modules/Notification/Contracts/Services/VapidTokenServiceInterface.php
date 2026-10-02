<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

/**
 * Credencial VAPID do ambiente (ADR-001).
 *
 * O par é do ambiente e não de um tenant: trocar a chave invalida toda
 * assinatura já feita, o navegador recusa e a fila limpa os tokens mortos.
 */
interface VapidTokenServiceInterface
{
    /** O ambiente tem par de chaves? Sem ele, o push não é enviado. */
    public function isConfigured(): bool;

    /**
     * Chave pública em base64url — é a `applicationServerKey` que o navegador
     * assina e o que o frontend lê do ambiente.
     */
    public function publicKey(): string;

    /**
     * JWT ES256 de curta duração que autentica o envio.
     *
     * @param  string  $audience  origem do serviço de push que vai receber a mensagem
     */
    public function token(string $audience): string;
}
