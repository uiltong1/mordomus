<?php

namespace Mordomus\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberGrantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capability' => ['required', 'string', 'max:64', Rule::exists('permissions', 'key')],
            'granted' => ['required', 'boolean'],
        ];
    }
}
