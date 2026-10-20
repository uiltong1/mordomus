<?php

namespace Mordomus\Financial\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Financial\Models\Bill;
use Mordomus\Scheduling\Models\TriggerConfig;

final class BillResource
{
    public function make(Bill $bill, string $timezone): array
    {
        return [
            'id' => $bill->id,
            'tenant_id' => $bill->tenant_id,
            'name' => $bill->name,
            'kind' => $bill->kind,
            'category' => $bill->category,
            'amount' => $bill->amount,
            'currency' => $bill->currency,
            'is_active' => $bill->isActive(),
            'schedule' => $this->schedule($bill, $timezone),
            'created_by' => $bill->created_by,
            'created_at' => $bill->created_at?->toIso8601String(),
            'updated_at' => $bill->updated_at?->toIso8601String(),
        ];
    }

    public function collection(iterable $bills, string $timezone): array
    {
        return collect($bills)
            ->map(fn (Bill $bill): array => $this->make($bill, $timezone))
            ->values()
            ->all();
    }

    private function schedule(Bill $bill, string $timezone): ?array
    {
        $config = $this->config($bill);

        if ($config === null) {
            return null;
        }

        return [
            'id' => $config->id,
            'type' => $config->type,
            'day_of_month' => $config->day_of_month,
            'advance_notice_days' => $config->advance_notice_days,
            'preferred_hour' => $config->preferred_hour,
            'is_active' => $config->isActive(),
            'next_due_at' => $this->format($config->next_due_at, $timezone),
        ];
    }

    private function config(Bill $bill): ?TriggerConfig
    {
        $active = $bill->schedules->firstWhere('is_active', true);

        return $active ?? $bill->schedules->first();
    }

    private function format(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->timezone($timezone)->toIso8601String();
    }
}
