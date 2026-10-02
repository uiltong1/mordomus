<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mordomus\Notification\Contracts\Services\PushChannelInterface;
use Mordomus\Notification\Contracts\Services\VapidTokenServiceInterface;
use Mordomus\Notification\Contracts\Services\WebPushEncryptionServiceInterface;
use Mordomus\Notification\Models\DeviceToken;
use RuntimeException;

/**
 * Web Push para navegador (ADR-001).
 *
 * A entrega é um `POST` direto no `endpoint` da assinatura — não existe
 * intermediário nosso, e é o que mantém o módulo sem depender de fornecedor. O
 * corpo vai cifrado (RFC 8291) e a autenticação é o JWT VAPID no cabeçalho.
 *
 * O canal é quem traduz o conteúdo gravado na linha para o formato do service
 * worker, porque o payload da rede é contrato do **worker** e o conteúdo é
 * contrato do módulo. As duas coisas estão na mesma chave `body` do
 * `notification_logs` e em formatos diferentes por um motivo só: a rede fala
 * com o navegador, e não com o banco.
 *
 * O status é o contrato com o serviço de push, e ele tem três respostas que
 * merecem decisões diferentes:
 *
 * - **2xx** entregou;
 * - **404/410** a assinatura não existe mais (o navegador limpou os dados do
 *   site, o Chrome trocou a chave do push) — a linha sai, porque tentar de novo
 *   é tentar para sempre;
 * - **401/403/429/5xx** o serviço não deu resposta utilizável — isso é
 *   exceção, e exceção é retry.
 */
final class WebPushChannel implements PushChannelInterface
{
    private const GONE_STATUSES = [404, 410];

    public function __construct(
        private readonly WebPushEncryptionServiceInterface $encryption,
        private readonly VapidTokenServiceInterface $vapid,
    ) {}

    public function send(DeviceToken $device, array $content): bool
    {
        if (! $this->vapid->isConfigured()) {
            throw new RuntimeException('VAPID sem par de chaves — o push não pode ser assinado neste ambiente.');
        }

        $body = $this->encryption->encrypt(
            (string) json_encode($this->wire($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $device->p256dh,
            $device->auth,
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => sprintf('vapid t=%s, k=%s', $this->vapid->token($this->audience($device)), $this->vapid->publicKey()),
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) config('notification.delivery.ttl'),
            ])
                ->timeout((int) config('notification.delivery.timeout'))
                ->withBody($body, 'application/octet-stream')
                ->post($device->endpoint);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                sprintf('O serviço de push não respondeu para o endpoint do morador %s.', $device->user_id),
                previous: $exception,
            );
        }

        if ($response->successful()) {
            return true;
        }

        if (in_array($response->status(), self::GONE_STATUSES, true)) {
            Log::info('notification.device_gone', [
                'tenant_id' => $device->tenant_id,
                'user_id' => $device->user_id,
                'device_token_id' => $device->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        throw new RuntimeException(sprintf(
            'O serviço de push recusou a mensagem com %d para o morador %s.',
            $response->status(),
            $device->user_id,
        ));
    }

    /**
     * O payload do service worker (ADR-001).
     *
     * As linhas viram uma frase só porque o card do push tem uma linha de
     * texto, e o `tag` vem junto para que o mesmo aviso substitua o card
     * anterior em vez de empilhar outro.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function wire(array $content): array
    {
        return [
            'title' => (string) ($content['title'] ?? 'Mordomus'),
            'body' => trim(implode(' ', array_map(
                fn (mixed $line): string => is_scalar($line) ? (string) $line : '',
                (array) ($content['lines'] ?? []),
            ))),
            'icon' => '/icon-192.png',
            'badge' => '/icon-192.png',
            'tag' => (string) ($content['tag'] ?? 'mordomus'),
            'url' => (string) ($content['url'] ?? '/'),
            'data' => $content['data'] ?? null,
        ];
    }

    /**
     * A origem do serviço de push, e não a do endpoint: é a origem que o
     * serviço valida contra o `aud` do token VAPID.
     */
    private function audience(DeviceToken $device): string
    {
        $parts = parse_url($device->endpoint);

        $scheme = is_array($parts) && isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';

        return $scheme.'://'.$host;
    }
}
