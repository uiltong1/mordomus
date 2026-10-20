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

    private function audience(DeviceToken $device): string
    {
        $parts = parse_url($device->endpoint);

        $scheme = is_array($parts) && isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';

        return $scheme.'://'.$host;
    }
}
