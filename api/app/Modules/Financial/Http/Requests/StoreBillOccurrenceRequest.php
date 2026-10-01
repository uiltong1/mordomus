<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lançamento manual de vencimento — o caminho da conta variável.
 *
 * A data é a que o morador leu na fatura e vai como veio: este módulo não
 * calcula data (regra R7). `amount` é obrigatório aqui porque não há valor
 * previsto para cair, e o valor da conta só existe depois da fatura.
 */
class StoreBillOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bill_id' => ['required', 'string', 'size:26'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
