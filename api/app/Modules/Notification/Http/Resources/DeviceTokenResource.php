<?php

namespace Mordomus\Notification\Http\Resources;

use Mordomus\Notification\Models\DeviceToken;

/**
 * Shape da assinatura, como o frontend já consome.
 *
 * `p256dh`, `auth` e `fcm_token` não saem: são segredo do lado do navegador, e
 * quem os tem é o próprio navegador. A tela precisa do `id`, do `endpoint` (para
 * desinscrever) e do `last_seen_at` (para saber se a assinatura ainda é usada).
 */
final class DeviceTokenResource
{
    /**
     * @return array<string, mixed>
     */
    public function make(DeviceToken $device): array
    {
        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'endpoint' => $device->endpoint,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<DeviceToken>  $devices
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $devices): array
    {
        return collect($devices)
            ->map(fn (DeviceToken $device): array => $this->make($device))
            ->values()
            ->all();
    }
}
