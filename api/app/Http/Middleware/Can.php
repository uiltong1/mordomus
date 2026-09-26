<?php

namespace Mordomus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checagem de capability (ADR-007) declarativa em rota.
 *
 * Uso: ->middleware('capability:rules.edit')
 *
 * Resolve pelo Gate já registrado no AppServiceProvider
 * (membership_grants → role_permissions → cache 60 s). O alias `capability`
 * existe porque `can` já é reservado pelo Laravel (Authorize).
 */
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

    /**
     * @param  array<string, mixed>  $details
     */
    private function deny(Request $request, int $status, string $code, string $message, array $details = []): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $status);
    }
}
