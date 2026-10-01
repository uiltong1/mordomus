<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Financial\Models\PaymentRecord;

/**
 * Baixa de pagamento.
 *
 * `amount` é opcional porque em conta de valor conhecido o morador não precisa
 * digitar o que já está no cadastro; `paid_at` também, porque o padrão é o
 * instante da baixa. Os dois existem para quem paga retroativo ou registra
 * depois.
 */
class PayBillOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'method' => ['required', Rule::in(PaymentRecord::METHODS)],
            'paid_at' => ['sometimes', 'date'],
            'receipt_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        ];
    }
}
