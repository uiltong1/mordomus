<?php

namespace Mordomus\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:40'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'acquired_at' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
