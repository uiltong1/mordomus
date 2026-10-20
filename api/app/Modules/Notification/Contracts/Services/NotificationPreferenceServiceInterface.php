<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Illuminate\Http\Request;

interface NotificationPreferenceServiceInterface
{
    public function show(Request $request): array;

    public function update(Request $request): array;
}
