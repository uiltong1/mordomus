<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Illuminate\Http\Request;

interface NotificationLogServiceInterface
{
    public function index(Request $request): array;
}
