<?php

namespace Mordomus\Financial\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Financial\Models\Bill;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Shape da conta, com a cadência que ela pediu ao Scheduling.
 *
 * `schedule` é a regra viva da conta: dia do vencimento, antecedência do aviso
 * e a próxima data que o motor já calculou. Vem junto porque quem cadastra a
 * conta é quem precisa conferir a data — e a resposta sem ela obrigaria a uma
 * segunda chamada a outro módulo.
 */
final class BillResource
{
    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param  iterable<Bill>  $bills
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $bills, string $timezone): array
    {
        return collect($bills)
            ->map(fn (Bill $bill): array => $this->make($bill, $timezone))
            ->values()
            ->all();
    }

    /** A regra ativa da conta, ou a última conhecida quando ela foi pausada. */
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

    /**
     * A regra que manda é a ativa; sem ela, a conta não tem mais cadência e a
     * última conhecida é informação, não comando.
     */
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
