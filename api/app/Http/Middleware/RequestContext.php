<?php

declare(strict_types=1);

namespace Mordomus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mordomus\Support\Logging\LogContext;
use Symfony\Component\HttpFoundation\Response;

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

        if ($minted) {
            $response->headers->set('X-Request-Id', $contextId);
        }

        return $response;
    }
}
