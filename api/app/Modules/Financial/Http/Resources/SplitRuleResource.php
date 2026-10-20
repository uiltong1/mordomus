<?php

namespace Mordomus\Financial\Http\Resources;

use Mordomus\Financial\Models\SplitEntry;
use Mordomus\Financial\Models\SplitRule;

final class SplitRuleResource
{
    public function make(SplitRule $rule): array
    {
        return [
            'id' => $rule->id,
            'tenant_id' => $rule->tenant_id,
            'bill_id' => $rule->bill_id,
            'bill_name' => $rule->bill?->name,
            'mode' => $rule->mode,
            'is_active' => $rule->isActive(),
            'is_house_default' => $rule->isHouseDefault(),
            'entries' => $this->entries($rule),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    public function collection(iterable $rules): array
    {
        return collect($rules)
            ->map(fn (SplitRule $rule): array => $this->make($rule))
            ->values()
            ->all();
    }

    private function entries(SplitRule $rule): array
    {
        return $rule->orderedEntries()
            ->map(fn (SplitEntry $entry): array => [
                'id' => $entry->id,
                'user_id' => $entry->user_id,
                'user_name' => $entry->user?->name,
                'weight' => $entry->weight,
                'percent' => $entry->percent,
                'fixed_amount' => $entry->fixed_amount,
            ])
            ->values()
            ->all();
    }
}
