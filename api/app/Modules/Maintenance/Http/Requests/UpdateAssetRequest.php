<?php

namespace Mordomus\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_id' => ['sometimes', 'string', 'size:26'],
            'name' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:60'],
            'model' => ['sometimes', 'nullable', 'string', 'max:60'],
            'acquired_at' => ['sometimes', 'nullable', 'date'],
            'warranty_until' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $fields = ['room_id', 'name', 'category', 'brand', 'model', 'acquired_at', 'warranty_until', 'metadata', 'archived'];
                $provided = array_filter($fields, fn (string $field) => $this->has($field));

                if ($provided === []) {
                    $validator->errors()->add('_', 'Informe ao menos um campo para atualizar (room_id, name, category, brand, model, acquired_at, warranty_until, metadata ou archived).');
                }
            },
        ];
    }
}
