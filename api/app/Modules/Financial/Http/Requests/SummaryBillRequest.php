<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consolidação mensal: o mês é opcional e, sem ele, vale o mês corrente da
 * residência no fuso dela.
 */
class SummaryBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
        ];
    }
}
