<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Support\Collection;
use Mordomus\Financial\Models\SplitResult;

interface SplitResultRepositoryInterface
{
    public function forOccurrence(string $tenantId, string $billOccurrenceId): Collection;

    public function find(string $tenantId, string $billOccurrenceId, string $userId): ?SplitResult;

    public function sync(string $tenantId, string $billOccurrenceId, array $shares): Collection;

    public function forget(string $tenantId, string $billOccurrenceId): int;

    public function settle(SplitResult $result, bool $settled, ?string $settledAt): SplitResult;
}
