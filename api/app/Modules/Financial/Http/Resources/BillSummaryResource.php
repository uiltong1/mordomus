<?php

namespace Mordomus\Financial\Http\Resources;

final class BillSummaryResource
{
    public function make(array $summary): array
    {
        return [
            'month' => $summary['month'],
            'timezone' => $summary['timezone'],
            'totals' => $summary['totals'],
            'counts' => $summary['counts'],
            'by_status' => $summary['by_status'],
            'by_category' => $summary['by_category'],
        ];
    }
}
