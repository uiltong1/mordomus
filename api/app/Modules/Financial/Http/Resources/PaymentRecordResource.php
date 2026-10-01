<?php

namespace Mordomus\Financial\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Financial\Models\PaymentRecord;

/**
 * Shape da baixa de pagamento.
 *
 * Sem `tenant_id`: o lançamento só existe dentro do vencimento que o traz, e
 * repeti-lo aqui só criaria a chance de os dois divergirem.
 */
final class PaymentRecordResource
{
    /**
     * @return array<string, mixed>
     */
    public function make(PaymentRecord $payment, string $timezone): array
    {
        return [
            'id' => $payment->id,
            'bill_occurrence_id' => $payment->bill_occurrence_id,
            'user_id' => $payment->user_id,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'paid_at' => $this->format($payment->paid_at, $timezone),
            'receipt_url' => $payment->receipt_url,
        ];
    }

    /**
     * @param  iterable<PaymentRecord>  $payments
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $payments, string $timezone): array
    {
        return collect($payments)
            ->map(fn (PaymentRecord $payment): array => $this->make($payment, $timezone))
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
