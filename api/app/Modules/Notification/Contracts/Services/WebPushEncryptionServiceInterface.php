<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

interface WebPushEncryptionServiceInterface
{
    public function encrypt(string $plaintext, string $p256dh, string $auth): string;
}
