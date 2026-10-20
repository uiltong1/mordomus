<?php

namespace Mordomus\Http\Tenancy;

use Illuminate\Http\Request;

final class ActiveTenant
{
    public static function id(Request $request): ?string
    {
        $claims = $request->attributes->get('jwt_claims');

        if (! is_array($claims)) {
            return null;
        }

        $tenantId = $claims['tid'] ?? null;

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
