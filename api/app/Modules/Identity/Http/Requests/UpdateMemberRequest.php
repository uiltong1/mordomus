<?php

namespace Mordomus\Identity\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateMemberRequest extends TenantScopedRequest
{
    protected function requiredCapability(): ?string
    {
        return 'members.manage';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'string', 'size:26', Rule::exists('roles', 'id')],
        ];
    }
}
