<?php

namespace Mordomus\Financial\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Mordomus\Financial\Models\SplitRule;

trait SplitModeRules
{
    private static function requiredByMode(): array
    {
        return [
            SplitRule::MODE_WEIGHTED => 'weight',
            SplitRule::MODE_PERCENT => 'percent',
            SplitRule::MODE_CUSTOM => 'fixed_amount',
        ];
    }

    private static function modeValidators(): array
    {
        return [
            'weight' => ['numeric', 'min:0.01', 'max:9999.99'],
            'percent' => ['numeric', 'min:0.01', 'max:999.99'],
            'fixed_amount' => ['numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    private static function modeRules(string $mode): array
    {
        $required = self::requiredByMode()[$mode] ?? null;
        $rules = [];

        foreach (self::modeValidators() as $field => $validators) {
            $rules['entries.*.'.$field] = $field === $required
                ? array_merge(['required'], $validators)
                : ['prohibited'];
        }

        return $rules;
    }

    private function participantsOf(?string $tenantId): array
    {
        return [Rule::exists('memberships', 'user_id')->where(
            fn ($query) => $query->where('tenant_id', $tenantId)->where('status', 'active'),
        )];
    }
}
