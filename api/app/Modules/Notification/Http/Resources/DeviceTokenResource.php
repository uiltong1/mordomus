<?php

namespace Mordomus\Notification\Http\Resources;

use Mordomus\Notification\Models\DeviceToken;

final class DeviceTokenResource
{
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

    public function collection(iterable $devices): array
    {
        return collect($devices)
            ->map(fn (DeviceToken $device): array => $this->make($device))
            ->values()
            ->all();
    }
}
