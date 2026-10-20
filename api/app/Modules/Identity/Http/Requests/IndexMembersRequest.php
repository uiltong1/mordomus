<?php

declare(strict_types=1);

namespace Mordomus\Identity\Http\Requests;

class IndexMembersRequest extends TenantScopedRequest
{
    public function rules(): array
    {
        return [];
    }
}
