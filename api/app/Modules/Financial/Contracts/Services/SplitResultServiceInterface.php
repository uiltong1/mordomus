<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\SplitRule;

interface SplitResultServiceInterface
{
    public function show(Request $request, string $billOccurrenceId): array;

    public function settle(Request $request, string $billOccurrenceId): array;

    public function compute(BillOccurrence $occurrence): Collection;

    public function recalculateForRule(string $tenantId, SplitRule $rule): int;
}
