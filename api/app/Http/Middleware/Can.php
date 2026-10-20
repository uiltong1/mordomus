<?php

namespace Mordomus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Http\Responses\ErrorEnvelope;
use Mordomus\Support\Logging\LogContext;
use Symfony\Component\HttpFoundation\Response;

class Can
{
    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny($request, 401, 'unauthenticated', 'Autenticação necessária.');
        }

        foreach ($capabilities as $capability) {
            if (! $user->can($capability)) {
                return $this->deny($request, 403, 'forbidden', 'Capability ausente.', [
                    'required' => $capability,
                ]);
            }
        }

        return $next($request);
    }

    private function deny(Request $request, int $status, string $code, string $message, array $details = []): Response
    {
        Log::warning($status === 401 ? 'auth.unauthenticated' : 'capability.denied', [
            'status' => $status,
            'code' => $code,
            'required' => $details['required'] ?? null,
            'user_id' => TenantContext::userId(),
            'tenant_id' => TenantContext::tenantId(),
            'path' => LogContext::path($request),
        ]);

        return ErrorEnvelope::make($request, $status, $code, $message, $details);
    }
}
