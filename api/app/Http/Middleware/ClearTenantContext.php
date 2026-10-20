<?php

declare(strict_types=1);

namespace Mordomus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mordomus\Common\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

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
