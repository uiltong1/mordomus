<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;

/**
 * Consolidação mensal da residência: quanto entrou, quanto saiu e o que ficou
 * em aberto.
 */
interface BillSummaryServiceInterface
{
    /** @return array<string, mixed> */
    public function summary(Request $request): array;
}
