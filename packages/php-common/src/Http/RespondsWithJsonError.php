<?php

namespace Mordomus\Common\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Formato padrão de erro da API.
 *
 * { "error": { "code", "message", "details", "request_id" } }
 */
trait RespondsWithJsonError
{
    protected function jsonError(Request $request, int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $status);
    }
}
