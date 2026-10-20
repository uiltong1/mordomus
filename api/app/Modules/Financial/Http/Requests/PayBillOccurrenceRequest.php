<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Financial\Models\PaymentRecord;

class PayBillOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
