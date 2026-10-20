<?php

declare(strict_types=1);

namespace Mordomus\Support\Logging;

use Illuminate\Http\Request;

final class LogContext
{
    public static function path(Request $request): string
    {
        return self::mask($request->path());
    }

    public static function url(Request $request): string
    {
        return self::mask($request->fullUrl());
    }

    private static function mask(string $uri): string
    {

        return (string) preg_replace('#/invitations/[^/?]+#', '/invitations/{token}', $uri);
    }
}
