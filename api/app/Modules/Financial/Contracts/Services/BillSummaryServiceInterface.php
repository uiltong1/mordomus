<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;

interface BillSummaryServiceInterface
{
    public function summary(Request $request): array;
}
