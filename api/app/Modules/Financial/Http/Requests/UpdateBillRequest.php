<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Financial\Models\Bill;

/**
 * Alteração da conta.
 *
 * No patch, os campos omitidos mantêm o que já estava gravado. `due_day` e
 * `advance_notice_days` continuam opcionais: só entram quando a cadência muda,
 * porque reenviá-los a cada edição reescreveria a regra do Scheduling sem
 * necessidade.
 */
class UpdateBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(Bill::KINDS)],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'is_active' => ['sometimes', 'boolean'],
            'due_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'advance_notice_days' => ['sometimes', 'integer', 'between:0,365'],
        ];
    }
}
