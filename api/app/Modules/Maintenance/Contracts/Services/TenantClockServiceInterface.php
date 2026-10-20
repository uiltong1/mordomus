<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Contracts\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

interface TenantClockServiceInterface
{
    public function timezone(Request $request): string;

    public function instant(Request $request, string $field, string $timezone): ?Carbon;
}
