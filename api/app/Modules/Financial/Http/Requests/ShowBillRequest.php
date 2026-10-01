<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Detalhe da conta não recebe payload: o alvo vem da URL e a autorização é a
 * do middleware de tenant.
 */
class ShowBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
