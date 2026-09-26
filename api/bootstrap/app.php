<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mordomus\Http\Middleware\Can;
use Mordomus\Http\Middleware\ClearTenantContext;
use Mordomus\Http\Middleware\TenantScope;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // sem rota de login: 401 JSON em vez de route('login')
        $middleware->redirectGuestsTo(fn () => null);

        // TenantContext é estático e o worker FPM é reutilizado — limpa antes
        // e depois de cada requisição (regra R1)
        $middleware->prepend(ClearTenantContext::class);

        $middleware->alias([
            'tenant' => TenantScope::class,
            'capability' => Can::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // formato de erro TECHSPEC §4.4: {error:{code,message,details,request_id}}
        $envelope = fn (Request $request, int $status, string $code, string $message, array $details = []) => response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $status);

        $exceptions->render(function (ValidationException $exception, Request $request) use ($envelope) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return $envelope($request, 422, 'validation_failed', $exception->getMessage(), $exception->errors());
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($envelope) {
            if (! $request->is('api/*')) {
                return null;
            }

            return $envelope($request, 401, 'unauthenticated', 'Autenticação necessária.');
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($envelope) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $message = trim($exception->getMessage());

            $defaultCodes = [
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                410 => 'gone',
                419 => 'page_expired',
                429 => 'rate_limited',
                500 => 'server_error',
            ];

            $code = preg_match('/^[a-z][a-z0-9_]*$/', $message) === 1
                ? $message
                : ($defaultCodes[$status] ?? 'http_error');

            if (preg_match('/^[a-z][a-z0-9_]*$/', $message) !== 1) {
                $message = match ($status) {
                    401 => 'Autenticação necessária.',
                    403 => 'Operação não permitida.',
                    404 => 'Recurso não encontrado.',
                    429 => 'Muitas requisições — tente novamente em instantes.',
                    default => $status >= 500 ? 'Erro interno.' : 'Requisição inválida.',
                };
            }

            return $envelope($request, $status, $code, $message);
        });
    })->create();
