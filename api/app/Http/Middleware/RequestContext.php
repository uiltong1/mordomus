<?php

declare(strict_types=1);

namespace Mordomus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mordomus\Support\Logging\LogContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlação da requisição: gera/recupera o `X-Request-Id` e o publica no
 * contexto compartilhado do logger, para evento de log, envelope de erro e
 * resposta falarem do mesmo identificador.
 */
final class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::withoutContext();
        Log::flushSharedContext();

        $incoming = $request->header('X-Request-Id');
        $minted = $incoming === null || $incoming === '';
        $contextId = $minted ? (string) Str::ulid() : $incoming;

        if ($minted) {
            $request->headers->set('X-Request-Id', $contextId);
        }

        $request->attributes->set('request_id', $contextId);

        Log::shareContext([
            'contextId' => $contextId,
            'traceId' => $contextId,
            'request_id' => $contextId,
            'method' => $request->getMethod(),
            'url' => LogContext::url($request),
        ]);

        $response = $next($request);

        // a borda já reemite o header recebido; só ecoamos o que nasceu aqui
        if ($minted) {
            $response->headers->set('X-Request-Id', $contextId);
        }

        return $response;
    }
}
