<?php

namespace Mordomus\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTenantRequest extends FormRequest
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
            'timezone' => ['sometimes', 'string', 'max:64'],
            'preferred_hour' => ['sometimes', 'date_format:H:i'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $fields = ['name', 'timezone', 'preferred_hour', 'archived'];
                $provided = array_filter($fields, fn (string $field) => $this->has($field));

                if ($provided === []) {
                    $validator->errors()->add('_', 'Informe ao menos um campo para atualizar (name, timezone, preferred_hour ou archived).');
                }
            },
        ];
    }
}
