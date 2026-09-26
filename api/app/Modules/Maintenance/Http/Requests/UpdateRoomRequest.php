<?php

namespace Mordomus\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:80'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $fields = ['name', 'icon', 'sort_order', 'archived'];
                $provided = array_filter($fields, fn (string $field) => $this->has($field));

                if ($provided === []) {
                    $validator->errors()->add('_', 'Informe ao menos um campo para atualizar (name, icon, sort_order ou archived).');
                }
            },
        ];
    }
}
