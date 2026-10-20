<?php

declare(strict_types=1);

namespace Tests\Support;

use Mordomus\Notification\Contracts\Services\PushChannelInterface;
use Mordomus\Notification\Models\DeviceToken;

final class FakePushChannel implements PushChannelInterface
{
    public array $sent = [];

    public function __construct(private array $endpoints = []) {}

    public function send(DeviceToken $device, array $content): bool
    {
        $outcome = $this->endpoints[(string) $device->endpoint] ?? true;

        if ($outcome === 'refuse') {
            throw new \RuntimeException('O serviço de push recusou a mensagem com 500.');
        }

        if ($outcome === true) {
            $this->sent[] = ['endpoint' => (string) $device->endpoint, 'content' => $content];
        }

        return $outcome;
    }
}
