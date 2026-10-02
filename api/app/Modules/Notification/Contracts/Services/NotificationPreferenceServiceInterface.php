<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Illuminate\Http\Request;

interface NotificationPreferenceServiceInterface
{
    /** @return array<string, mixed> */
    public function show(Request $request): array;

    /** @return array<string, mixed> */
    public function update(Request $request): array;
}
