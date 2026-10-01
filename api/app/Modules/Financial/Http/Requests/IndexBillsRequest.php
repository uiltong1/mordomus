<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Financial\Models\Bill;

class IndexBillsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'nullable', Rule::in(Bill::KINDS)],
            'is_active' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
