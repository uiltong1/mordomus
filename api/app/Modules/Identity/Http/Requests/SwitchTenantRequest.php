<?php

namespace Mordomus\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SwitchTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'size:26'],
        ];
    }

    public function messages(): array
    {
        return [
            'tenant_id.required' => 'Informe a residência de destino.',
            'tenant_id.size' => 'Identificador de residência inválido.',
        ];
    }
}
