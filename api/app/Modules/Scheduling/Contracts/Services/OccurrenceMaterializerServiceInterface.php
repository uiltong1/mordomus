<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Carbon\CarbonImmutable;
use Mordomus\Scheduling\Models\JobSchedule;
use Mordomus\Scheduling\Models\TriggerConfig;

interface OccurrenceMaterializerServiceInterface
{
    public function materialize(string $tenantId, int $horizonDays): int;

    public function materializeDate(TriggerConfig $config, CarbonImmutable $date): ?JobSchedule;
}
