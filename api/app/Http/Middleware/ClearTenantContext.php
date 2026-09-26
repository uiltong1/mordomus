<?php

declare(strict_types=1);

namespace Mordomus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mordomus\Common\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limpa o TenantContext (estático) no início e no fim de cada requisição.
 *
 * O worker FPM é reutilizado entre requisições: sem esta barreira um tenant
 * resolvido na requisição anterior contaminaria a seguinte (regra R1).
 */
class ClearTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::clear();

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
