<?php

namespace Mordomus\Identity\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateMemberGrantsRequest extends TenantScopedRequest
{
    protected function requiredCapability(): ?string
    {
        return 'members.manage';
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
