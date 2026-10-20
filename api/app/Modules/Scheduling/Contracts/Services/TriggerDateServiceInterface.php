<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Models\TriggerConfig;

interface TriggerDateServiceInterface
{
    public function nextDue(TriggerConfig $config, ?CarbonImmutable $base, string $timezone): ?CarbonImmutable;

    public function dueAt(CarbonImmutable $scheduledFor, string $timezone, string $preferredHour): CarbonImmutable;
}
