<?php

namespace Mordomus\Financial\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Financial\Models\BillOccurrence;

/**
 * Shape do vencimento, com as baixas que o quitam.
 *
 * `due_date` é dia de calendário e sai no fuso da residência: ele é o dia que
 * o morador lê na fatura, não um instante. O histórico de pagamento vai
 * embutido porque é a resposta natural da pergunta que a lista de vencimentos
 * faz — "isso já foi pago, por quem e como".
 */
final class BillOccurrenceResource
{
    public function __construct(private readonly PaymentRecordResource $payments) {}

    /**
     * @return array<string, mixed>
     */
    public function make(BillOccurrence $occurrence, string $timezone): array
    {
        return [
            'id' => $occurrence->id,
            'tenant_id' => $occurrence->tenant_id,
            'bill_id' => $occurrence->bill_id,
            'bill_name' => $occurrence->bill?->name,
            'bill_kind' => $occurrence->bill?->kind,
            'category' => $occurrence->bill?->category,
            'schedule_id' => $occurrence->schedule_id,
            'due_date' => $occurrence->dueDate(),
            'amount' => $occurrence->amount,
            'status' => $occurrence->status,
            'paid_at' => $this->format($occurrence->paid_at, $timezone),
            'paid_by' => $occurrence->paid_by,
            'payments' => $this->payments->collection($occurrence->payments, $timezone),
            'created_at' => $occurrence->created_at?->toIso8601String(),
            'updated_at' => $occurrence->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<BillOccurrence>  $occurrences
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $occurrences, string $timezone): array
    {
        return collect($occurrences)
            ->map(fn (BillOccurrence $occurrence): array => $this->make($occurrence, $timezone))
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
