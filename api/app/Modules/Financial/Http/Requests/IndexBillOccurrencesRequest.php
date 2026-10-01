<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Financial\Models\BillOccurrence;

class IndexBillOccurrencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `month` e `from`/`to` são dois jeitos de dizer o mesmo recorte: aceitos
     * juntos, um deles seria silenciosamente ignorado.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => [
                'sometimes',
                'nullable',
                'date_format:Y-m',
                Rule::prohibitedIf(fn (): bool => $this->filled('from') || $this->filled('to')),
            ],
            'from' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                Rule::prohibitedIf(fn (): bool => $this->filled('month')),
            ],
            'to' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                Rule::prohibitedIf(fn (): bool => $this->filled('month')),
            ],
            'bill_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'status' => ['sometimes', 'nullable', Rule::in(BillOccurrence::STATUSES)],
        ];
    }
}
