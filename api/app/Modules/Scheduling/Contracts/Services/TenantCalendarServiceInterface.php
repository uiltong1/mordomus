<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

interface TenantCalendarServiceInterface
{
    public function timezone(string $tenantId): string;

    public function preferredHour(?string $configHour, string $tenantId): string;

    public function day(CarbonInterface|string $day, string $tenantId): CarbonImmutable;
}
