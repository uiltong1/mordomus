<?php

namespace Mordomus\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'lowercase'],
            'role_id' => ['nullable', 'string', 'size:26', Rule::exists('roles', 'id')],
        ];
    }
}
