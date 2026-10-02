<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

interface WebPushEncryptionServiceInterface
{
    /**
     * Cifra o corpo da notificação para uma assinatura (RFC 8291, aes128gcm).
     *
     * @param  string  $plaintext  corpo JSON da notificação
     * @param  string  $p256dh  chave pública do assinante, base64url
     * @param  string  $auth  segredo de autenticação do assinante, base64url
     * @return string corpo pronto para o POST, base64url
     */
    public function encrypt(string $plaintext, string $p256dh, string $auth): string;
}
