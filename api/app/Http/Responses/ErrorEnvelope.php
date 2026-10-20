<?php

declare(strict_types=1);

namespace Mordomus\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ErrorEnvelope
{
    public static function make(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $details = [],
    ): JsonResponse {
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
