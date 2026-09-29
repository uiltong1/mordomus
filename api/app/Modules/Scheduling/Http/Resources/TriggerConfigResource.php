<?php

namespace Mordomus\Scheduling\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Shape da regra, com o alvo no par `subject_type`/`subject_id` e as datas no
 * fuso da residência.
 */
final class TriggerConfigResource
{
    /**
     * @return array<string, mixed>
     */
    public function make(TriggerConfig $config, string $timezone): array
    {
        return [
            'id' => $config->id,
            'tenant_id' => $config->tenant_id,
            'subject_type' => $config->subject_type,
            'subject_id' => $config->subjectId(),
            'title' => $config->title,
            'description' => $config->description,
            'is_active' => $config->isActive(),
            'type' => $config->type,
            'interval_value' => $config->interval_value,
            'interval_unit' => $config->interval_unit,
            'day_of_month' => $config->day_of_month,
            'advance_notice_days' => $config->advance_notice_days,
            'recalculate_base' => $config->recalculate_base,
            'custom_offsets' => $config->custom_offsets,
            'preferred_hour' => $config->preferred_hour,
            'last_base_date' => $config->last_base_date?->format('Y-m-d'),
            'next_due_at' => $this->format($config->next_due_at, $timezone),
            'created_at' => $config->created_at?->toIso8601String(),
            'updated_at' => $config->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<TriggerConfig>  $configs
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $configs, string $timezone): array
    {
        return collect($configs)
            ->map(fn (TriggerConfig $config): array => $this->make($config, $timezone))
            ->values()
            ->all();
    }

    private function format(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->timezone($timezone)->toIso8601String();
    }
}
