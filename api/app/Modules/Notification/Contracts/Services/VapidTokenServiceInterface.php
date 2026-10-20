<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

interface VapidTokenServiceInterface
{
    public function isConfigured(): bool;

    public function publicKey(): string;

    public function token(string $audience): string;
}
