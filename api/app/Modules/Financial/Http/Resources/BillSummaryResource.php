<?php

namespace Mordomus\Financial\Http\Resources;

/**
 * Shape da consolidação mensal.
 *
 * Os valores são texto decimal com duas casas, não número: a soma de centavo é
 * o que fecha com o total (regra R5), e binário de ponto flutuante fecharia por
 * sorte na maioria dos casos e erraria nos centavos.
 */
final class BillSummaryResource
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
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
