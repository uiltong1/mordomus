<?php

namespace Mordomus\Http\Tenancy;

use Illuminate\Http\Request;

/**
 * Residência ativa da requisição, tal como validada pelo middleware `tenant`
 * a partir do claim `tid` do JWT — nunca do corpo nem de header do cliente.
 */
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
