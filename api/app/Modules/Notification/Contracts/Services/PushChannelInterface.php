<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Mordomus\Notification\Models\DeviceToken;

interface PushChannelInterface
{
    public function send(DeviceToken $device, array $content): bool;
}
