<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexSplitRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bill_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }

    public function billId(): ?string
    {
        $billId = $this->input('bill_id');

        return is_string($billId) && $billId !== '' ? $billId : null;
    }
}
