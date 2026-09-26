<?php

declare(strict_types=1);

namespace Mordomus\Support\Logging;

use Illuminate\Http\Request;

/**
 * URIs prontas para log — segmentos com segredo são mascarados antes de
 * gravar, porque o logger não tem como saber o que é caminho variável.
 */
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
        // o token do convite vive no path e nunca pode chegar ao log
        return (string) preg_replace('#/invitations/[^/?]+#', '/invitations/{token}', $uri);
    }
}
