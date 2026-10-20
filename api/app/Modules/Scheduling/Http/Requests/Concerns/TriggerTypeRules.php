<?php

namespace Mordomus\Scheduling\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Mordomus\Scheduling\Models\TriggerConfig;

trait TriggerTypeRules
{
    private static function requiredByType(): array
    {
        return [
            TriggerConfig::TYPE_INTERVAL => ['interval_value', 'interval_unit'],
            TriggerConfig::TYPE_CALENDAR_MONTHLY => ['day_of_month'],
            TriggerConfig::TYPE_POST_COMPLETION => ['interval_value', 'interval_unit', 'recalculate_base'],

            TriggerConfig::TYPE_ESCALATED => ['interval_value', 'interval_unit', 'custom_offsets'],
        ];
    }

    private static function typeValidators(): array
    {
        return [
            'interval_value' => ['integer', 'min:1', 'max:65535'],
            'interval_unit' => [Rule::in(TriggerConfig::INTERVAL_UNITS)],
            'day_of_month' => ['integer', 'between:1,31'],
            'recalculate_base' => [Rule::in([TriggerConfig::RECALCULATE_DUE_DATE, TriggerConfig::RECALCULATE_COMPLETION])],
            'custom_offsets' => ['array', 'min:1'],
            'custom_offsets.*' => ['integer', 'between:-365,365'],
        ];
    }

    private static function typeRules(?string $type, bool $typeChanged): array
    {
        $required = self::requiredByType()[$type] ?? [];
        $rules = [];

        foreach (self::typeValidators() as $field => $validators) {

            if (str_contains($field, '*')) {
                continue;
            }

            $belongs = in_array($field, $required, true);

            $rules[$field] = match (true) {
                ! $belongs => ['prohibited'],
                $typeChanged => array_merge(['required'], $validators),
                default => array_merge(['sometimes'], $validators),
            };
        }

        if (in_array('custom_offsets', $required, true)) {
            $rules['custom_offsets.*'] = self::typeValidators()['custom_offsets.*'];
        }

        return $rules;
    }

    private static function sharedRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$presence, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'advance_notice_days' => ['sometimes', 'integer', 'between:0,365'],
            'preferred_hour' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
