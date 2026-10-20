<?php

namespace Mordomus\Financial\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\SplitRule;

final class OccurrenceSplitResource
{
    public function make(
        BillOccurrence $occurrence,
        SplitRule $rule,
        array $shares,
        string $scope,
        string $timezone,
    ): array {
        return [
            'bill_occurrence_id' => $occurrence->id,
            'bill_id' => $occurrence->bill_id,
            'bill_name' => $occurrence->bill?->name,
            'due_date' => $occurrence->dueDate(),
            'amount' => (string) $occurrence->amount,
            'status' => $occurrence->status,
            'split_rule_id' => $rule->id,
            'mode' => $rule->mode,
            'is_house_default' => $rule->isHouseDefault(),
            'total' => (string) $occurrence->amount,
            'scope' => $scope,
            'shares' => $this->withInstants($shares, $timezone),
        ];
    }

    private function withInstants(array $shares, string $timezone): array
    {
        return array_map(

            fn (array $share): array => array_merge(
                $share,
                ['settled_at' => $this->format($share['settled_at'] ?? null, $timezone)],
            ),
            $shares,
        );
    }

    private function format(mixed $value, string $timezone): ?string
    {
        return $value === null
            ? null
            : Carbon::parse($value)->timezone($timezone)->toIso8601String();
    }
}
