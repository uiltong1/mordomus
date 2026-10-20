<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Carbon\CarbonImmutable;

interface DueNoticeServiceInterface
{
    public function publish(string $tenantId, CarbonImmutable $now): int;
}
