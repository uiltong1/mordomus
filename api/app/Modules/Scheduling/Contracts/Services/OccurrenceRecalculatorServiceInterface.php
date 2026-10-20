<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

interface OccurrenceRecalculatorServiceInterface
{
    public function recalculate(string $tenantId, string $occurrenceId): void;
}
