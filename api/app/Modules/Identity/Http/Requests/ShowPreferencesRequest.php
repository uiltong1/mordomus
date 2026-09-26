<?php

declare(strict_types=1);

namespace Mordomus\Identity\Http\Requests;

class ShowPreferencesRequest extends TenantScopedRequest
{
    protected function requiresMembership(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
