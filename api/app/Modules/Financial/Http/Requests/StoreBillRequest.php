<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Financial\Models\Bill;

/**
 * Cadastro da conta.
 *
 * `due_day` e `advance_notice_days` descrevem a cadência, e a validação é a do
 * Scheduling: quem materializa o dia do vencimento é o módulo dono do motor de
 * tempo, e o mesmo conjunto de campos que ele exige é o que chega na regra.
 */
class StoreBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(Bill::KINDS)],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'is_active' => ['sometimes', 'boolean'],
            'due_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'advance_notice_days' => ['sometimes', 'integer', 'between:0,365'],
        ];
    }
}
